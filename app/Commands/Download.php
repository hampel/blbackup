<?php

namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Symfony\Component\Console\Helper\ProgressBar;

class Download extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'download
                            {server? : download most recent backup for specified hostname or numeric server id}
                            {--i|id= : download specific backup image id}
                            {--f|force : force re-download of existing backup}
                            {--no-test : skip testing of downloaded files}
                            {--m|move : move files to secondary storage after downloading}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download backups to local storage';

    protected string $commandContext = 'download';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $imageId = $this->option('id');

        if ($imageId)
        {
            $link = $this->api->link($imageId);

            if (empty($link))
            {
                $this->fail("No download link found for image {$imageId}");
            }

            $image = $this->api->image($imageId);

            if (empty($image))
            {
                $this->fail("No image data returned for image {$imageId}");
            }

            $serverId = $image['backup_info']['server_id'];
            $server = $this->api->server($serverId);

            if (empty($server))
            {
                $this->fail("Could not find server {$serverId} for image {$imageId}");
            }
        }
        else
        {
            $hostnameOrServerId = $this->argument('server');

            if (empty($hostnameOrServerId))
            {
                $this->newLine();
                $this->line("Backup images");
                $this->newLine();

                // no options specified, just show a list of backup images
                $this->listImages();

                $this->fail("No hostname, server_id or image_id specified");
            }
            elseif (is_numeric($hostnameOrServerId))
            {
                $server = $this->api->server($hostnameOrServerId);

                if (empty($server)) {
                    $this->fail("Could not find server {$hostnameOrServerId}");
                }
            }
            else
            {
                $servers = $this->api->servers($hostnameOrServerId);

                if (empty($servers)) {
                    $this->fail("No server data returned for {$hostnameOrServerId}");
                }

                $server = $servers[0];
            }

            $backups = $this->api->backups($server);

            if (empty($backups))
            {
                $this->fail("No backup data returned for {$server['name']}");
            }

            $image = collect($backups)->sortBy('created_at')->last();
            $imageId = $image['id'];

            $link = $this->api->link($imageId);

            if (empty($link))
            {
                $this->fail("No download link found for image {$imageId}");
            }
        }

        $date = Carbon::createFromFormat("Y-m-d\TH:i:sT", $image['created_at'])->format("Ymd-His");
        $storagePath = $this->getStoragePath($server);
        $filePath = "{$storagePath}/backup-{$date}-{$imageId}.zst";

        $path = Storage::disk('downloads')->path($filePath);

        if (Storage::disk('downloads')->exists($filePath) && !$this->option('force'))
        {
            $this->log(
                'notice',
                "Backup file [{$filePath}] already exists, use the --force flag to over-ride",
                "Backup file already exists, aborting",
                ['path' => $filePath]
            );

            return self::SUCCESS;
        }

        $start = now();

        $this->download($link['disks'][0]['compressed_url'], $path);

        $timeFormatted = Number::format($start->diffInSeconds(now()), 1);
        $this->line("Completed download for {$server['name']} in {$timeFormatted} seconds");
        $this->newLine();

        if (!$this->option('no-test') && !$this->testDownload($path))
        {
            Storage::disk('downloads')->delete($filePath);
            return self::FAILURE;
        }

        $size = Storage::disk('downloads')->size($filePath);
        $sizeGb = $size / (1024 * 1024 * 1024);
        $expectedSize = $image['size_gigabytes'];

        if ($sizeGb != $expectedSize)
        {
            $this->fail("Download size of {$sizeGb} GB does not match expected {$expectedSize} GB");
        }

        $sizeFormatted = Number::format($sizeGb, 2);

        $this->line("Successfully downloaded {$sizeFormatted} GB to [{$filePath}]");

        if ($this->option('move'))
        {
            return $this->call('move', ['file' => $filePath]);
        }

        return self::SUCCESS;
    }

    protected function listImages()
    {
        $images = $this->api->images();

        if (empty($images))
        {
            $this->fail("No image data returned");
        }

        $table = collect($images)
            ->sortBy('id')
            ->reject(function ($image) {
                return $image['public'] == true || $image['type'] != 'backup';
            })
            ->map(function ($image) {
                return [
                    'image_id' => Str::padLeft($image['id'], 9),
                    'full_name' => $image['full_name'],
                    'created_at' => Carbon::createFromFormat("Y-m-d\TH:i:sT", $image['created_at'])->toDateTimeString(),
                    'size' => Str::padLeft(Number::format($image['size_gigabytes'], 2), 7),

                ];
            });

        $this->table(
            ['Image ID', 'Image Name', 'Created', 'Size GB'],
            $table
        );
    }

    protected function download(string $url, string $path) : void
    {
        $this->log(
            'notice',
            "Downloading [{$url}] to [{$path}]",
            "Downloading image",
            compact('url', 'path')
        );

        $progress = new ProgressBar($this->output, 100);
        $progress->start();

        $this->api->download(
            $url,
            $path,
            function ($downloadTotal, $downloadedBytes, $uploadTotal, $uploadedBytes) use ($progress) {
                if ($downloadTotal > 0)
                {
                    $progress->setProgress(intval(round(($downloadedBytes / $downloadTotal) * 100)));
                }
            });

        $progress->finish();
        $this->newLine();
    }

    protected function getStoragePath(array $server) : string
    {
        $storagePath = "{$server['name']}";

        if (!Storage::disk('downloads')->exists($storagePath))
        {
            Log::notice("Storage path doesn't exist, creating", ['path' => $storagePath]);
            Storage::disk('downloads')->makeDirectory($storagePath);
        }

        return $storagePath;
    }

    protected function testDownload(string $path) : bool
    {
        $binary = config('binarylane.zstd_binary');

        // TODO: add verbosity?
        $cmd = "{$binary} --test {$path}";

        $this->log(
            'notice',
            "Testing download [{$path}]",
            "Testing download",
            compact('cmd')
        );

        $result = Process::forever()->path(storage_path())->run($cmd);

        if ($result->failed())
        {
            $output = $result->errorOutput();

            $this->log(
                'error',
                "Downloaded file failed zstd test: " . $output,
                "Downloaded file failed zstd test",
                compact('path', 'output')
            );
        }

        return $result->successful();
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
