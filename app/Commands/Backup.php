<?php

namespace App\Commands;

use App\Api;
use App\Exceptions\BinaryLaneException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Number;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class Backup extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backup
                            {hostname? : backup only this hostname}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Backup BinaryLane servers';

    /**
     * Execute the console command.
     */
    public function handle(Api $api)
    {
        $hostname = $this->argument('hostname');
        $hostnameOutput = $hostname ? " for {$hostname}" : '';

        try
        {
            $servers = $api->servers($hostname);

            if (empty($servers))
            {
                $this->fail("No server data returned{$hostnameOutput}");
            }

            if (!$hostname)
            {
                $this->line("Backing up all servers");
            }

            $statuses = [];

            collect($servers)->dump()->each(function ($server) use (&$statuses, $api) {

                $this->line("Backing up {$server['name']}");

                $action = $api->backup($server);

                $statuses[$server['name']] = $action;

                $start = now();

                $progress = new ProgressBar($this->output, 100);
                $progress->start();

                Sleep::for(15)->seconds()->while(function () use (&$statuses, $server, $action, $api, $start, $progress) {
                    $status = $api->action($action['id']);

                    $progress->setProgress($status['progress']['percent_complete']);

                    $this->line(" {$status['progress']['current_step_detail']}");

                    dump($status);

                    $statuses[$server['name']] = $status;

                    if ($status['status'] == 'completed')
                    {
                        // we're done, we can stop sleeping now
                        return false;
                    }

                    if ($status['status'] == 'errored')
                    {
                        // something went wrong, stop waiting
                        return false;
                    }

                    if ($start->diffInSeconds(now()) > 3600)
                    {
                        // we've been running too long, stop
                        return false;
                    }

                    if ($status['status'] !== 'in-progress')
                    {
                        // something went wrong, stop just in case
                        return false;
                    }

                    // for everything else, continue
                    return true;
                });

                $status = $statuses[$server['name']]['status'];
                if ($status == 'completed')
                {
                    $progress->finish();
                    $this->newLine();

                    $timeFormatted = Number::format($start->diffInSeconds(now()), 1);
                    $this->line("Completed server backup {$server['name']} in {$timeFormatted} seconds");
                }
                else
                {
                    $this->error("Error backing up {$server['name']} - status: {$status}");
                }
            });

            return self::SUCCESS;
        }
        catch (BinaryLaneException $e)
        {
            $this->fail($e->getMessage());
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
