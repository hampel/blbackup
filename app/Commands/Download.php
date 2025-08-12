<?php

namespace App\Commands;

use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;
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
                            {--image= : download specific backup image id}
                            {--all : download most recent backups for all servers in account}
                            {--include= : include only this list of servers}
                            {--exclude= : exclude this list of servers}
                            {--f|force : force re-download of existing backup}
                            {--no-test : skip testing of downloaded files}
                            {--wget : use wget to fetch files rather than internet php code}
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
        $imageId = $this->option('image');

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

            return $this->downloadImage($image, $server, $link) ? self::SUCCESS : self::FAILURE;
        }

        $hostnameOrServerId = $this->argument('server');

        if ($this->option('all'))
        {
            $servers = $this->api->servers();

            if (empty($servers)) {
                $this->fail("No server data returned for {$hostnameOrServerId}");
            }
        }
        elseif (empty($hostnameOrServerId))
        {
            // no options specified, just show a list of backup images
            $this->call('backups');

            $this->fail("Specify a hostname, server_id or backup_id to download the backup");
        }
        elseif (is_numeric($hostnameOrServerId))
        {
            $server = $this->api->server($hostnameOrServerId);

            if (empty($server)) {
                $this->fail("Could not find server {$hostnameOrServerId}");
            }

            $servers[] = $server;
        }
        else
        {
            $servers = $this->api->servers($hostnameOrServerId);

            if (empty($servers)) {
                $this->fail("No server data returned for {$hostnameOrServerId}");
            }
        }

        $includeServers = null;
        $excludeServers = null;

        $include = $this->option('include');
        if (!empty($include))
        {
            if (!File::exists($include))
            {
                $this->fail("Include file [{$include}] does not exists or is not readable");
            }

            $includeServers = array_filter(explode(PHP_EOL, File::get($include)));
        }

        $exclude = $this->option('exclude');
        if (!empty($exclude))
        {
            if (!File::exists($exclude))
            {
                $this->fail("Exclude file [{$exclude}] does not exists or is not readable");
            }

            $excludeServers = array_filter(explode(PHP_EOL, File::get($exclude)));
        }

        collect($servers)
            ->filter(function ($server) use ($includeServers) {
                return $includeServers ? in_array($server['name'], $includeServers) : true;
            })
            ->reject(function ($server) use ($excludeServers) {
                return $excludeServers ? in_array($server['name'], $excludeServers) : false;
            })
            ->each(function ($server) {

                $backups = $this->api->backups($server);

                if (empty($backups))
                {
                    $this->log(
                        'warning',
                        "No backup data returned for {$server['name']}",
                        "No backup data returned",
                        ['server' => $server['name']]
                    );

                    return;
                }

                $image = collect($backups)->sortBy('created_at')->last();
                $imageId = $image['id'];

                $link = $this->api->link($imageId);

                if (empty($link))
                {
                    $this->log(
                        'warning',
                        "No download link found for {$server['name']} image {$imageId}",
                        "No download link found",
                        ['image_id' => $imageId, 'server' => $server['name']]
                    );

                    return;
                }

                $this->downloadImage($image, $server, $link);
            });

        return self::SUCCESS;
    }

    protected function downloadImage(array $image, array $server, array $link) : bool
    {
        $date = Carbon::createFromFormat("Y-m-d\TH:i:sT", $image['created_at'])->format("Ymd-His");
        $storagePath = $this->getStoragePath($server);
        $filePath = "{$storagePath}/backup-{$date}-{$image['id']}.zst";

        $path = Storage::disk('downloads')->path($filePath);

        if (Storage::disk('downloads')->exists($filePath) && !$this->option('force'))
        {
            $size = Storage::disk('downloads')->size($filePath);
            $sizeGb = $size / (1024 * 1024 * 1024);
            $expectedSize = $image['size_gigabytes'];

            if ($sizeGb == $expectedSize)
            {
                // file already exists, is the expected size, and we aren't forcing download - so notify and return

                $this->log(
                    'notice',
                    "Backup file for {$server['name']} already exists at [{$filePath}], use the --force flag to over-ride",
                    "Backup file already exists, aborting",
                    ['server' => $server['name'], 'path' => $filePath]
                );

                return false;
            }

            // file already exists, but size doesn't match expected - incomplete download?
            $this->log(
                'warning',
                "Backup file for {$server['name']} already exists at [{$filePath}], but size {$sizeGb} GB does not match expected {$expectedSize} GB, consider re-downloading using --force parameter",
                "Existing file size does not match expected, but size does not match expected",
                ['server' => $server['name'], 'path' => $filePath, 'size_gb' => $sizeGb, 'expected_size' => $expectedSize]
            );

            return false;

            // TODO: check file size and if smaller than expected, resume downloading

        }

        $url = $link['disks'][0]['compressed_url'];

        $this->log(
            'notice',
            "Downloading {$server['name']} image from [{$url}] to [{$path}]",
            "Downloading image",
            ['server' => $server['name'], 'url' => $url, 'path' => $path]
        );

        $start = now();

        if ($this->option('wget'))
        {
            // use wget binary
            if (!$this->downloadWget($url, $path))
            {
                return false;
            }
        }
        else
        {
            // use API
            $this->download($url, $path);
        }

        $timeFormatted = Number::format($start->diffInSeconds(now()), 1);
        $this->line("Completed download for {$server['name']} in {$timeFormatted} seconds");
        $this->newLine();

        if (!$this->option('no-test') && !$this->testDownload($path))
        {
            $this->log(
                'warning',
                "Deleting invalid backup file for {$server['name']} from [{$path}]",
                "Deleting invalid download file",
                ['server' => $server['name'], 'path' => $path]
            );

            Storage::disk('downloads')->delete($filePath);
            return false;
        }

        $size = Storage::disk('downloads')->size($filePath);
        $sizeGb = $size / (1024 * 1024 * 1024);
        $expectedSize = $image['size_gigabytes'];

        if ($sizeGb != $expectedSize)
        {
            $this->log(
                'error',
                "Downloaded backup file for {$server['name']}, size of {$sizeGb} GB does not match expected {$expectedSize} GB",
                "Download size does not match expected",
                ['server' => $server['name'], 'path' => $filePath, 'size_gb' => $sizeGb, 'expected_size' => $expectedSize]
            );

            return false;
        }

        $sizeFormatted = Number::format($sizeGb, 2);

        $this->line("Successfully downloaded {$sizeFormatted} GB to [{$filePath}]");

        if ($this->option('move'))
        {
            return $this->call('move', ['file' => $filePath]);
        }

        return true;
    }

    protected function download(string $url, string $path) : void
    {
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

    protected function downloadWget(string $url, string $path) : bool
    {
        $wget = config('binarylane.wget_binary');

        $cmd = "{$wget} {$url} -O {$path}";

        $result = $this->processWget($cmd, Storage::disk('downloads')->path(''));

        if ($result->failed())
        {
            $output = $result->errorOutput();

            $this->log(
                'error',
                "Could not download file: " . $output,
                "Could not download file",
                compact('output', 'cmd')
            );
        }

        return $result->successful();
    }

    protected function getStoragePath(array $server) : string
    {
        $storagePath = "{$server['name']}";

        if (!Storage::disk('downloads')->exists($storagePath))
        {
            $this->log(
                'notice',
                "Storage path does not exist for {$server['name']}, creating",
                "Storage path does not exist, creating",
                ['path' => $storagePath]
            );

            Storage::disk('downloads')->makeDirectory($storagePath);
        }

        return $storagePath;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
