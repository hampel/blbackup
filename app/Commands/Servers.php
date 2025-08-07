<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Str;

class Servers extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'servers
                            {hostname? : hostname or numeric server id to list data for}
                            {--ids : just list server IDs}
                            {--names : just list server names}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List server info';

    protected string $commandContext = 'servers';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        // TODO - add "servers" option to list server names to help generate include/exclude files

        $hostname = $this->argument('hostname');
        $hostnameOutput = $hostname ? " for {$hostname}" : '';

        if (is_numeric($hostname))
        {
            // $hostname is server_id
            $server = $this->api->server($hostname);

            $servers[] = $server;
        }
        else
        {
            $servers = $this->api->servers($hostname);
        }

        if (empty($servers))
        {
            $this->fail("No server data returned{$hostnameOutput}");
        }

        if ($this->option('ids'))
        {
            collect($servers)
                ->sortBy('id')
                ->each(function ($server) {
                    $this->line($server['id']);
                });
        }
        elseif ($this->option('names'))
        {
            collect($servers)
                ->sortBy('name')
                ->each(function ($server) {
                    $this->line($server['name']);
                });
        }
        else
        {
            $this->newLine();
            $this->line("Servers");
            $this->newLine();

            $table = collect($servers)->sortBy('id')->map(function ($server) {
                return [
                    'id' => $server['id'],
                    'name' => $server['name'],
                    'memory' => Str::padLeft($server['memory'], 6),
                    'vcpus' => Str::padLeft($server['vcpus'], 5),
                    'disk' => Str::padLeft($server['disk'], 4),
                ];
            });

            $this->table(
                ['ID', 'Name', 'Memory', 'VCPUs', 'Disk'],
                $table
            );
        }

        return self::SUCCESS;
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
