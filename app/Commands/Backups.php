<?php

namespace App\Commands;

use Carbon\Carbon;
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
            $images = $this->api->images();

            if (empty($images))
            {
                $this->fail("No image data returned");
            }

            if ($this->option('ids'))
            {
                collect($images)->sortBy('id')
                    ->reject(function ($image) {
                        return $image['public'] == true || $image['type'] != 'backup';
                    })
                    ->each(function ($image) {
                        $this->line($image['id']);
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
            $server = $this->api->server($hostnameOrServerId);
        }
        else
        {
            $servers = $this->api->servers($hostnameOrServerId);

            if (empty($servers))
            {
                $this->fail("No server data returned for {$hostnameOrServerId}");
            }

            $server = $servers[0];
        }

        $backups = $this->api->backups($server);

        if (empty($backups))
        {
            $this->fail("No backup data returned for {$hostnameOrServerId}");
        }

        if ($this->option('ids'))
        {
            collect($backups)->sortBy('id')->each(function ($backup) {
                $this->line($backup['id']);
            });
        }
        else
        {
            $this->newLine();
            $this->line("Backups for {$server['name']} ({$server['id']}):");
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
            ->reject(function ($image) {
                return $image['public'] == true || $image['type'] != 'backup';
            })
            ->map(function ($image) use (&$links) {

                if ($this->option('urls'))
                {
                    $links[$image['id']] = $this->api->link($image['id']);
                }

                $created = Carbon::createFromFormat("Y-m-d\TH:i:sT", $image['created_at']);

                return [
                    'image_id' => Str::padLeft($image['id'], 9),
                    'full_name' => $image['full_name'],
                    'created_at' => $created->toDateTimeString(),
                    'created_at_local' => $created->timezone(config('binarylane.timezone'))->toDateTimeString(),
                    'size' => Str::padLeft(Number::format($image['size_gigabytes'], 2), 7),
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

        collect($links)->each(function ($link) {
            $this->line("Backup ID: {$link['id']}");
            $this->line($link['disks'][0]['compressed_url']);
            $this->newLine();
        });
    }
}
