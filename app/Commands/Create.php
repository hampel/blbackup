<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Number;
use Illuminate\Support\Sleep;
use Symfony\Component\Console\Helper\ProgressBar;

class Create extends BaseCommand
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create
                            {server? : hostname or numeric server id to create backups for}
                            {--a|all : create backups for all servers in account}
                            {--d|download : also download each backup created}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create new server backup';

    protected string $commandContext = 'Create Backup';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $hostnameOrServerId = $this->argument('server');
        $allServers = $this->option('all');

        if (!$allServers)
        {
            if (empty($hostnameOrServerId))
            {
                $this->fail("No hostname or server_id specified. Specify --all option to back up all servers");
            }

            if (is_numeric($hostnameOrServerId))
            {
                // $hostname is server_id
                $server = $this->api->server($hostnameOrServerId);

                if (empty($server))
                {
                    $this->fail("No server data returned for server_id {$hostnameOrServerId}");
                }

                $servers[] = $server;
            }
            else
            {
                // hostname is string
                $servers = $this->api->servers($hostnameOrServerId);

                if (empty($servers))
                {
                    $this->fail("No server data returned for {$hostnameOrServerId}");
                }
            }
        }
        else
        {
            $servers = $this->api->servers();

            if (empty($servers))
            {
                $this->fail("No server data returned");
            }

            $this->log('notice', "Backing up all servers");
        }

        collect($servers)->each(function ($server) {

            if ($this->backup($server) && $this->option('download'))
            {
                $this->call('download', ['--server' => $server['id']]);
            }

        });

        return self::SUCCESS;
    }

    protected function backup(array $server) : bool
    {
        // TODO: implement exclude list

        $this->log(
            'notice',
            "Backing up {$server['disk']}GB from {$server['name']}",
            "Backing up server",
            ['server_id' => $server['id'], 'disk' => $server['disk'], 'name' => $server['name']]
        );

        $start = now();

        $action = $this->api->createBackup($server);

        $progress = new ProgressBar($this->output, 100);
        $progress->start();

        $timeout = config('binarylane.timeout');

        Sleep::for(10)->seconds()->while(function () use ($server, $action, $start, $progress, $timeout, &$status) {
            $status = $this->api->action($action['id']);

            $progress->setProgress($status['progress']['percent_complete']);

            // don't write this out to console, will break the progress bar - just log it
            Log::debug("Backup progress", ['current_step_detail' => $status['progress']['current_step_detail']]);

            if ($status['status'] == 'completed') {
                // we're done, we can stop sleeping now
                return false;
            }

            if ($status['status'] == 'errored') {
                // something went wrong, stop waiting
                return false;
            }

            if ($start->diffInSeconds(now()) > $timeout) {
                // we've been running too long, stop

                $this->log(
                    'warning',
                    "Backup of {$server['name']} exceeded timeout of {$timeout} seconds",
                    "Backup exceeded timeout",
                    ['server_id' => $server['id'], 'name' => $server['name'], 'timeout' => $timeout]
                );

                return false;
            }

            if ($status['status'] !== 'in-progress') {
                // something went wrong, stop just in case
                return false;
            }

            // for everything else, continue
            return true;
        });

        if ($status['status'] == 'completed')
        {
            $progress->finish();
            $this->newLine();

            $timeFormatted = Number::format($start->diffInSeconds(now()), 1);
            $this->log(
                'notice',
                "Completed server backup {$server['name']} in {$timeFormatted} seconds",
                "Completed server backup",
                ['time_seconds' => $timeFormatted, 'server_id' => $server['id'], 'disk' => $server['disk'], 'name' => $server['name']]
            );
            $this->newLine();

            return true;
        }
        else
        {
            $this->log(
                'error',
                "Error backing up {$server['name']} - status: {$status['status']}",
                "Error backing up server",
                ['status' => $status['status'], 'server_id' => $server['id'], 'disk' => $server['disk'], 'name' => $server['name']]
            );

            return false;
        }
    }

    /**
     * Define the command's schedule.
     */
    public function schedule(Schedule $schedule): void
    {
        // $schedule->command(static::class)->everyMinute();
    }
}
