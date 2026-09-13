<?php

namespace App\Commands;

use App\Support\ImageDownloader;
use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Hampel\BinaryLane\Api\Entity\Image;
use Hampel\BinaryLane\Api\Entity\Server;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Contracts\Process\ProcessResult;
use Illuminate\Support\Facades\Process;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Number;

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
                            {--no-wget : do not use wget to download files}
                            {--m|move : move files to secondary storage after downloading}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Download backups to local storage';

    protected string $commandContext = 'download';

    protected bool $locks = true;

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $imageId = $this->option('image');

        if ($imageId)
        {
            // an id that is not a backup on this account raises rather than
            // answering empty, and BaseCommand reports it
            $imageId = (int) $imageId;

            $url = $this->compressedUrl($this->binarylane->images()->download($imageId));

            if ($url === null)
            {
                $this->fail("No download link found for image {$imageId}");
            }

            $image = $this->binarylane->images()->get($imageId);

            $serverId = $image->backupInfo?->serverId;

            if (empty($serverId))
            {
                $this->fail("Image {$imageId} is not the backup of a server");
            }

            $server = $this->binarylane->servers()->get($serverId);

            return $this->downloadImage($image, $server, $url) ? self::SUCCESS : self::FAILURE;
        }

        $hostnameOrServerId = $this->argument('server');

        if ($this->option('all'))
        {
            $servers = $this->allServers();

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
            $servers = [$this->binarylane->servers()->get((int) $hostnameOrServerId)];
        }
        else
        {
            $servers = $this->serversNamed($hostnameOrServerId);

            if (empty($servers)) {
                $this->fail("No server data returned for {$hostnameOrServerId}");
            }
        }

        $includeServers = $this->serverList('include');
        $excludeServers = $this->serverList('exclude');

        $failed = collect($servers)
            ->filter(function (Server $server) use ($includeServers) {
                return $includeServers ? in_array($server->name, $includeServers) : true;
            })
            ->reject(function (Server $server) use ($excludeServers) {
                return $excludeServers ? in_array($server->name, $excludeServers) : false;
            })
            // reject rather than each, so one server that fails doesn't stop the
            // run and what is left is the servers with no backup in place
            ->reject(function (Server $server) {

                // every page - a server's backups rarely fill one, but a first page
                // that stopped short would quietly download an older image
                $backups = iterator_to_array($this->binarylane->servers()->eachBackup($server->id), false);

                if (empty($backups))
                {
                    $this->log(
                        'warning',
                        "No backup data returned for {$server->name}",
                        "No backup data returned",
                        ['server' => $server->name]
                    );

                    $this->summary->recordFailure($server->name, 'download', 'no backup data returned');

                    return false;
                }

                $image = collect($backups)->sortBy(fn (Image $backup) => $backup->createdAt)->last();
                $imageId = $image->id;

                $url = $this->compressedUrl($this->binarylane->images()->download($imageId));

                if ($url === null)
                {
                    $this->log(
                        'warning',
                        "No download link found for {$server->name} image {$imageId}",
                        "No download link found",
                        ['image_id' => $imageId, 'server' => $server->name]
                    );

                    return false;
                }

                return $this->downloadImage($image, $server, $url);
            });

        if ($failed->isNotEmpty())
        {
            $this->log(
                'error',
                "{$failed->count()} backup(s) could not be downloaded",
                "Backups could not be downloaded",
                ['count' => $failed->count(), 'servers' => $failed->pluck('name')->all()]
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    /**
     * Returns whether the backup is in place afterwards - true when it was
     * downloaded and true when it was already there, false only when something
     * went wrong. Callers use it for the exit code, so a skip must not read as
     * a failure.
     */
    protected function downloadImage(Image $image, Server $server, string $url) : bool
    {
        if ($image->createdAt === null)
        {
            // the filename is built from it, and so are the already-downloaded
            // check and clean's expiry - an image without one cannot be placed
            $this->log(
                'error',
                "Backup image {$image->id} for {$server->name} has no creation date to name the download by",
                "Backup image has no creation date",
                ['image_id' => $image->id, 'server' => $server->name]
            );

            return false;
        }

        $date = CarbonImmutable::instance($image->createdAt)
            ->setTimezone(config('blbackup.timezone'))
            ->format("Ymd-His");

        if (!Storage::disk('downloads')->exists($server->name))
        {
            $this->log(
                'info',
                "Download path does not exist for {$server->name}, creating",
                "Download path does not exist, creating",
                ['server' => $server->name]
            );

            Storage::disk('downloads')->makeDirectory($server->name);
        }

        $serverName = collect(explode('.', $server->name))->first();

        $path = "{$server->name}/backup-{$serverName}-{$date}-{$image->id}.zst";

        $expectedSize = $image->sizeGigabytes;

        if (!$this->option('force'))
        {
            if (Storage::disk('downloads')->exists($path))
            {
                // file already exists locally and we're not forcing downloads

                $size = Storage::disk('downloads')->size($path);
                $sizeGb = $size / (1024 * 1024 * 1024);

                if ($sizeGb == $expectedSize)
                {
                    // file already exists, is the expected size, and we aren't forcing download - so notify and return

                    $this->log(
                        'notice',
                        "Backup file for {$server->name} already exists at [{$path}], use the --force flag to over-ride",
                        "Backup file already exists, aborting",
                        ['server' => $server->name, 'path' => $path]
                    );

                    // nothing to do, and nothing wrong: the backup is here
                    return true;
                }

                // file already exists, but size doesn't match expected - incomplete download?
                $this->log(
                    'warning',
                    "Backup file for {$server->name} already exists at [{$path}], but size {$sizeGb} GB does not match expected {$expectedSize} GB, consider re-downloading using --force parameter",
                    "Existing file already exists, but size does not match expected",
                    ['server' => $server->name, 'path' => $path, 'size_gb' => $sizeGb, 'expected_size' => $expectedSize]
                );

                return false;

                // TODO: check file size and if smaller than expected, resume downloading

            }

            if ($this->option('move'))
            {
                $remoteFile = $this->remoteFileInfo($path);

                if (!empty($remoteFile))
                {
                    $sizeGb = intval($remoteFile['Size'] ?? 0) / (1024 * 1024 * 1024);

                    if ($sizeGb == $expectedSize) {
                        // file already exists, is the expected size, and we aren't forcing download - so notify and return

                        $this->log(
                            'notice',
                            "Backup file for {$server->name} already exists on remote at [{$path}], use the --force flag to over-ride",
                            "Backup file already exists on remote, aborting",
                            ['server' => $server->name, 'path' => $path]
                        );

                        // nothing to do, and nothing wrong: the backup is shipped
                        return true;
                    }

                    // file already exists, but size doesn't match expected - incomplete download?
                    $this->log(
                        'warning',
                        "Backup file for {$server->name} already exists on remote at [{$path}], but size {$sizeGb} GB does not match expected {$expectedSize} GB, consider re-downloading using --force parameter",
                        "Existing file already exists on remote, but size does not match expected",
                        ['server' => $server->name, 'path' => $path, 'size_gb' => $sizeGb, 'expected_size' => $expectedSize]
                    );

                    return false;
                }
            }
        }

        $this->log(
            'notice',
            "Downloading {$server->name} image from [{$url}] to [{$path}]",
            "Downloading image",
            ['server' => $server->name, 'url' => $url, 'path' => $path]
        );

        $fullPath = Storage::disk('downloads')->path($path);

        $start = now();

        if ($this->option('no-wget'))
        {
            // use Http client
            $this->downloadHttp($url, $fullPath);
        }
        else
        {
            // use wget binary
            if (!$this->downloadWget($url, $fullPath))
            {
                return false;
            }
        }

        $seconds = $start->diffInSeconds(now());
        $mb = $expectedSize * 1024;
        $speed = Number::format($mb / $seconds, 1);

        $elapsed = CarbonInterval::seconds($seconds)->cascade()->forHumans();
        $secondsFormatted = Number::format($seconds, 1);

        $this->newLine();
        $this->log(
            'notice',
            "Completed download for {$server->name} in {$elapsed} ({$speed} MB/s)",
            "Completed download",
            ['server' => $server->name, 'elapsed' => $elapsed, 'seconds' => $secondsFormatted, 'megabytes_per_second' => $speed]
        );
        $this->newLine();

        if (!$this->option('no-test') && !$this->testDownload($path))
        {
            $this->log(
                'warning',
                "Deleting invalid backup file for {$server->name} from [{$path}]",
                "Deleting invalid download file",
                ['server' => $server->name, 'path' => $path]
            );

            Storage::disk('downloads')->delete($path);

            $this->summary->recordFailure($server->name, 'check', 'failed the zstd test and was deleted');

            return false;
        }

        $size = Storage::disk('downloads')->size($path);
        $sizeGb = $size / (1024 * 1024 * 1024);
        $expectedSize = $image->sizeGigabytes;

        if ($sizeGb != $expectedSize)
        {
            $this->log(
                'error',
                "Downloaded backup file for {$server->name}, size of {$sizeGb} GB does not match expected {$expectedSize} GB",
                "Download size does not match expected",
                ['server' => $server->name, 'path' => $path, 'size_gb' => $sizeGb, 'expected_size' => $expectedSize]
            );

            $this->summary->recordFailure(
                $server->name, 'download',
                "downloaded {$sizeGb} GB, expected {$expectedSize} GB"
            );

            return false;
        }

        $sizeFormatted = Number::format($sizeGb, 2);

        $this->line("Successfully downloaded {$sizeFormatted} GB to [{$path}]");

        $this->summary->recordDownload($server->name, $path, $size);

        if ($this->option('move'))
        {
            return $this->call('move', ['file' => $path]) === self::SUCCESS;
        }

        return true;
    }

    protected function testDownload(string $path) : bool
    {
        return $this->call('check', ['file' => $path]) == self::SUCCESS ? true : false;
    }

    protected function downloadHttp(string $url, string $path) : void
    {
        $progress = $this->progressBar();
        $progress->start();

        $this->app->make(ImageDownloader::class)->download(
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
        $wget = config('blbackup.wget_binary');

        $cmd = "{$wget} {$url} -O {$path}";

        $this->logCmd('wget', $cmd);

        $result = $this->processWget($cmd, Storage::disk('downloads')->path(''));

        if ($result->failed())
        {
            $output = trim($result->errorOutput());

            $this->log(
                'error',
                "Could not download file: " . $output,
                "Could not download file",
                compact('output', 'cmd')
            );
        }

        return $result->successful();
    }

    protected function processWget(string $cmd, string $path = '') : ProcessResult
    {
        $count = 0;
        $lineCount = 0;
        $last = '';

        $progress = $this->progressBar();

        $result = Process::forever()
            ->path($path)
            ->run($cmd, function (string $type, string $output) use (&$count, &$lineCount, &$last, $progress) {

                collect(explode(PHP_EOL, $output))
                    ->reject(function (string $line) {
                        return empty($line);
                    })
                    ->each(function (string $line) use (&$count, &$lineCount, &$last, $progress) {
                        if ($lineCount < 8)
                        {
                            if (preg_match("/\.\.\.\./", $line))
                            {
                                // skip
                            }
                            else
                            {
                                $this->line($line);
                            }
                        }
                        elseif ($lineCount == 8)
                        {
                            $progress->start();
                        }
                        elseif (preg_match("/^\s*\d*\w\s*[\.\s]+(\d+)\%\s+[\d\.]+\w\s+[\d\w]+$/", $line, $matches))
                        {
                            $progress->setProgress(intval($matches[1]));
                        }
                        elseif (preg_match("/^\d{4}-\d{2}-\d{2}\s\d{2}:\d{2}:\d{2}\s\(/", $line))
                        {
                            $last = $line;
                        }
                        elseif (preg_match("/\.\.\./", $line))
                        {
                            // skip these partial lines
                        }
                        elseif (strlen(trim($line, " .")) <= 20)
                        {
                            // skip short lines
                        }
                        else
                        {
                            $this->line("[{$line}]");
                        }

                        $lineCount++;
                    });

                $count++;
            });

        $progress->finish();
        $this->newLine(2);

        $this->line($last);

        return $result;
    }

    protected function remoteFileInfo(string $path) : ?array
    {
        $rclone = config('blbackup.rclone.binary');
        $remotePath = rtrim(config('blbackup.rclone.remote'), '/');

        $cmd = "{$rclone} lsjson --stat --no-mimetype --no-modtime {$remotePath}/{$path}";

        $this->logCmd('rclone lsjson', $cmd);

        $result = Process::path(storage_path())->run($cmd);

        if ($result->successful())
        {
            try
            {
                return json_decode($result->output(), true, 512, JSON_THROW_ON_ERROR);
            }
            catch (\JsonException $e)
            {
                $this->log(
                    'error',
                    "Could not decode json data for remote file info: " . $e->getMessage(),
                    "Could not decode json info",
                    ['error' => $e->getMessage()]
                );

                return null;
            }
        }

        return [];
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
