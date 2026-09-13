<?php

namespace App\Commands;

use Carbon\CarbonImmutable;
use Hampel\BinaryLane\Api\Entity\Image;
use Hampel\BinaryLane\Api\Entity\ImageDownload;
use Hampel\BinaryLane\Api\Enum\ImageType;
use Illuminate\Support\Number;
use Illuminate\Support\Str;

class Backups extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backups
                            {server? : hostname or numeric server id to list backups for}
                            {--ids : just list backup IDs}
                            {--urls : also list download URLs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List backups on BinaryLane';

    protected string $commandContext = 'backups';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hostnameOrServerId = $this->argument('server');

        if (empty($hostnameOrServerId))
        {
            // this account's own backups, across every page. The unfiltered image
            // list is mostly BinaryLane's operating system catalogue, and one page
            // of it held only the backups that happened to sort into the first
            // twenty - all of them today, and fewer once the account has more
            $images = iterator_to_array($this->binarylane->images()->backups(), false);

            if (empty($images))
            {
                $this->fail("No image data returned");
            }

            if ($this->option('ids'))
            {
                collect($images)->sortBy('id')
                    ->reject(fn (Image $image) => $this->isNotOwnBackup($image))
                    ->each(function (Image $image) {
                        $this->line((string) $image->id);
                    });
            }
            else
            {
                $this->newLine();
                $this->line("All backup images on BinaryLane");
                $this->newLine();

                // no options specified, just show a list of backup images
                $this->listImages($images);
            }

            return self::SUCCESS;
        }

        if (is_numeric($hostnameOrServerId))
        {
            // $hostname is server_id
            $server = $this->binarylane->servers()->get((int) $hostnameOrServerId);
        }
        else
        {
            $servers = $this->serversNamed($hostnameOrServerId);

            if (empty($servers))
            {
                $this->fail("No server data returned for {$hostnameOrServerId}");
            }

            $server = $servers[0];
        }

        $backups = iterator_to_array($this->binarylane->servers()->eachBackup($server->id), false);

        if (empty($backups))
        {
            $this->fail("No backup data returned for {$hostnameOrServerId}");
        }

        if ($this->option('ids'))
        {
            collect($backups)->sortBy('id')->each(function (Image $backup) {
                $this->line((string) $backup->id);
            });
        }
        else
        {
            $this->newLine();
            $this->line("Backups for {$server->name} ({$server->id}):");
            $this->newLine();

            $this->listImages($backups);
        }

        return self::SUCCESS;
    }

    protected function listImages(array $images)
    {
        $links = [];

        $table = collect($images)
            ->sortBy('id')
            ->reject(fn (Image $image) => $this->isNotOwnBackup($image))
            ->map(function (Image $image) use (&$links) {

                if ($this->option('urls'))
                {
                    $links[$image->id] = $this->binarylane->images()->download($image->id);
                }

                // UTC from the client, set explicitly all the same: the first column
                // is labelled UTC, and the process default is the configured timezone
                $created = $image->createdAt === null ? null : CarbonImmutable::instance($image->createdAt)->setTimezone('UTC');

                return [
                    'image_id' => Str::padLeft((string) $image->id, 9),
                    'full_name' => (string) $image->fullName,
                    'created_at' => $created?->toDateTimeString() ?? '',
                    'created_at_local' => $created?->setTimezone(config('blbackup.timezone'))->toDateTimeString() ?? '',
                    'size' => Str::padLeft(Number::format($image->sizeGigabytes, 2), 7),
                ];
            });

        $this->table(
            ['Backup ID', 'Backup Name', 'Created (UTC)', 'Created (local TZ)', 'Size GB'],
            $table
        );

        if ($this->option('urls'))
        {
            $this->listLinks($links);
        }
    }

    protected function listLinks(array $links)
    {
        $this->newLine();
        $this->line("Backup download URLs");
        $this->newLine();

        collect($links)->each(function (ImageDownload $link) {
            $this->line("Backup ID: {$link->id}");
            $this->line($this->compressedUrl($link) ?? '(no compressed download available)');
            $this->newLine();
        });
    }

    /**
     * An image this listing leaves out: a public one, or anything that is not a
     * backup.
     *
     * By type, deliberately, not the client's isBackup() - which also counts an
     * image carrying backup_info whatever its type, and would widen what this
     * listing has always shown.
     */
    protected function isNotOwnBackup(Image $image) : bool
    {
        return $image->public || $image->type !== ImageType::Backup;
    }
}
