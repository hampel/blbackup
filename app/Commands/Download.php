<?php

namespace App\Commands;

use App\Api;
use App\Exceptions\BinaryLaneException;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class Download extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'download
                            {--i|id= : download specific backup image id}
                            {--s|server= : download most recent backup for specified server_id}
                            {--o|hostname= : download most recent backup for specified hostname}
                            {--f|force : force re-download of existing backup}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download backups to local storage';

    protected Api $api;

    /**
     * Execute the console command.
     */
    public function handle(Api $api)
    {
        $this->api = $api;

        try
        {
            $imageId = $this->option('id');
            $serverId = $this->option('server');
            $hostname = $this->option('hostname');

            if ($imageId)
            {
                $link = $api->link($imageId);

                if (empty($link))
                {
                    $this->fail("No download link found for image {$imageId}");
                }

                $image = $api->image($imageId);

                if (empty($image))
                {
                    $this->fail("No image data returned for image {$imageId}");
                }

                $serverId = $image['backup_info']['server_id'];
                $server = $api->server($serverId);

                if (empty($server))
                {
                    $this->fail("Could not find server {$serverId} for image {$imageId}");
                }
            }
            else
            {
                if ($serverId)
                {
                    $server = $api->server($serverId);

                    if (empty($server)) {
                        $this->fail("Could not find server {$serverId}");
                    }
                }
                elseif ($hostname)
                {
                    $servers = $api->servers($hostname);

                    if (empty($servers)) {
                        $this->fail("No server data returned for {$hostname}");
                    }

                    $server = $servers[0];
                }
                else
                {
                    // no options specified, just show a list of backup images
                    $this->listImages();

                    return self::SUCCESS;
                }

                $backups = $api->backups($server);

                if (empty($backups))
                {
                    $this->fail("No backup data returned for {$server['name']}");
                }

                $image = collect($backups)->sortBy('created_at')->last();
                $imageId = $image['id'];

                $link = $api->link($imageId);

                if (empty($link))
                {
                    $this->fail("No download link found for image {$imageId}");
                }
            }

            $date = Carbon::createFromFormat("Y-m-d\TH:i:sT", $image['created_at'])->format("Ymd-His");
            $storagePath = $this->getStoragePath($server);
            $filePath = "{$storagePath}/backup-{$date}-{$imageId}.zst";

            $path = Storage::path($filePath);

            if (Storage::exists($filePath) && !$this->option('force'))
            {
                $this->line("Backup file [{$filePath}] already exists, use the --force flag to over-ride");
                return self::SUCCESS;
            }

            $start = now();

            $this->download($link['disks'][0]['compressed_url'], $path);

            $timeFormatted = Number::format($start->diffInSeconds(now()), 1);
            $this->line("Completed download for {$server['name']} in {$timeFormatted} seconds");
            $this->newLine();

            if (!$this->testDownload($path))
            {
                Storage::delete($filePath);
                return self::FAILURE;
            }

            $size = Storage::size($filePath);
            $sizeGb = $size / (1024 * 1024 * 1024);
            $expectedSize = $image['size_gigabytes'];

            if ($sizeGb != $expectedSize)
            {
                $this->fail("Download size of {$sizeGb} GB does not match expected {$expectedSize} GB");
            }

            $sizeFormatted = Number::format($sizeGb, 2);

            $this->line("Successfully downloaded {$sizeFormatted} GB to [{$filePath}]");

            return self::SUCCESS;
        }
        catch (BinaryLaneException $e)
        {
            $this->fail($e->getMessage());
        }
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
        $this->line("Downloading [{$url}] to [{$path}]");
        Log::info("Downloading image", compact('url', 'path'));

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
        $storagePath = "backups/{$server['name']}";

        if (!Storage::exists($storagePath))
        {
            Storage::makeDirectory($storagePath);
        }

        return $storagePath;
    }

    protected function testDownload(string $path) : bool
    {
        $binary = config('binarylane.zstd_binary');
        $cmd = "{$binary} --test {$path}";

        $this->line("Testing download [{$path}]");
        Log::debug("Testing download", compact('cmd'));

        $result = Process::forever()->path(storage_path())->run($cmd);

        if ($result->failed())
        {
            $this->line("Downloaded file failed zstd test: " . $result->errorOutput());
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
