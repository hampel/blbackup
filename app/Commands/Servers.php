<?php

namespace App\Commands;

use App\Api;
use App\Exceptions\BinaryLaneException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use LaravelZero\Framework\Commands\Command;

class Servers extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'servers
                            {hostname? : show only data for this hostname}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'List BinaryLane servers';

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

            $table = collect($servers)->map(function ($server) {
                return [
                    'id' => $server['id'],
                    'name' => $server['name'],
                    'memory' => Str::padLeft($server['memory'], 6, ' '),
                    'vcpus' => Str::padLeft($server['vcpus'], 5, ' '),
                    'disk' => Str::padLeft($server['disk'], 4, ' '),
                ];
            });

            $this->table(
                ['ID', 'Name', 'Memory', 'VCPUs', 'Disk'],
                $table
            );

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
