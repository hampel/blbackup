<?php

namespace App\Commands;

use Hampel\BinaryLane\Api\Entity\Server;
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
        $hostname = $this->argument('hostname');
        $hostnameOutput = $hostname ? " for {$hostname}" : '';

        if (is_numeric($hostname))
        {
            // $hostname is server_id
            $servers = [$this->binarylane->servers()->get((int) $hostname)];
        }
        elseif ($hostname)
        {
            $servers = $this->serversNamed($hostname);
        }
        else
        {
            $servers = $this->allServers();
        }

        if (empty($servers))
        {
            $this->fail("No server data returned{$hostnameOutput}");
        }

        if ($this->option('ids'))
        {
            collect($servers)
                ->sortBy('id')
                ->each(function (Server $server) {
                    $this->line((string) $server->id);
                });
        }
        elseif ($this->option('names'))
        {
            collect($servers)
                ->sortBy('name')
                ->each(function (Server $server) {
                    $this->line($server->name);
                });
        }
        else
        {
            $this->newLine();
            $this->line("Servers");
            $this->newLine();

            $table = collect($servers)->sortBy('id')->map(function (Server $server) {
                return [
                    'id' => $server->id,
                    'name' => $server->name,
                    'memory' => Str::padLeft((string) $server->memory, 6),
                    'vcpus' => Str::padLeft((string) $server->vcpus, 5),
                    'disk' => Str::padLeft((string) $server->disk, 4),
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
