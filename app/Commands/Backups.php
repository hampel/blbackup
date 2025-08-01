<?php

namespace App\Commands;

use App\Api;
use App\Exceptions\BinaryLaneException;
use Carbon\Carbon;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class Backups extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'backups
                            {hostname : hostname or numeric server id to list backups for}
                            {--i|id : just list backup IDs}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List backups for a server';

    /**
     * Execute the console command.
     */
    public function handle(Api $api)
    {
        $hostname = $this->argument('hostname');

        try
        {
            if (is_numeric($hostname))
            {
                // $hostname is server_id
                $server = $api->server($hostname);
            }
            else
            {
                $servers = $api->servers($hostname);

                if (empty($servers))
                {
                    $this->fail("No server data returned for {$hostname}");
                }

                $server = $servers[0];
            }

            $backups = $api->backups($server);

            if (empty($backups))
            {
                $this->fail("No backup data returned for {$hostname}");
            }

            if ($this->option('id'))
            {
                collect($backups)->each(function ($backup) {
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
