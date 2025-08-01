<?php

namespace App\Commands;

use App\Api;
use App\Exceptions\BinaryLaneException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Number;
use Illuminate\Support\Sleep;
use LaravelZero\Framework\Commands\Command;
use Symfony\Component\Console\Helper\ProgressBar;

class Create extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'create
                            {hostname? : backup only this hostname}
                            {--d|download : also download each backup created}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Create new server backup';

    protected Api $api;

    /**
     * Execute the console command.
     */
    public function handle(Api $api)
    {
        $this->api = $api;

        $hostname = $this->argument('hostname');
        $hostnameOutput = $hostname ? " for {$hostname}" : '';

        try
        {
            $servers = $this->api->servers($hostname);

            if (empty($servers))
            {
                $this->fail("No server data returned{$hostnameOutput}");
            }

            if (!$hostname)
            {
                $this->line("Backing up all servers");
            }

            collect($servers)->each(function ($server) {

                if ($this->backup($server) && $this->option('download'))
                {
                    $this->call('download', ['--server' => $server['id']]);
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

    protected function backup(array $server) : bool
    {
        // TODO: implement exclude list

        $this->line("Backing up {$server['name']}");

        $start = now();

        $action = $this->api->createBackup($server);

        $progress = new ProgressBar($this->output, 100);
        $progress->start();

        Sleep::for(15)->seconds()->while(function () use ($server, $action, $start, $progress, &$status) {
            $status = $this->api->action($action['id']);

            $progress->setProgress($status['progress']['percent_complete']);

            $this->line(" {$status['progress']['current_step_detail']}");

            if ($status['status'] == 'completed') {
                // we're done, we can stop sleeping now
                return false;
            }

            if ($status['status'] == 'errored') {
                // something went wrong, stop waiting
                return false;
            }

            if ($start->diffInSeconds(now()) > config('binarylane.timeout')) {
                // we've been running too long, stop
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
            $this->line("Completed server backup {$server['name']} in {$timeFormatted} seconds");
            $this->newLine();

            return true;
        }
        else
        {
            $this->error("Error backing up {$server['name']} - status: {$status['status']}");

            return false;
        }
    }
}
