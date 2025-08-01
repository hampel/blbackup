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
                            {--i|id : just list backup IDs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List backups for a server';

    protected string $commandContext = 'Backups';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hostnameOrServerId = $this->argument('server');

        if (empty($hostnameOrServerId))
        {
            $this->fail("No hostname specified");
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

        if ($this->option('id'))
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

            $table = collect($backups)->sortBy('id')->map(function ($backup) {
                return [
                    'backup_id' => Str::padLeft($backup['id'], 9),
                    'full_name' => $backup['full_name'],
                    'created_at' => Carbon::createFromFormat("Y-m-d\TH:i:sT", $backup['created_at'])->toDateTimeString(),
                    'size' => Str::padLeft(Number::format($backup['size_gigabytes'], 2), 7),
                ];
            });

            $this->table(
                ['Backup ID', 'Backup Name', 'Date Created', 'Size GB'],
                $table
            );
        }

        return self::SUCCESS;
    }
}
