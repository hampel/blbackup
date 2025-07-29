<?php

namespace App\Commands;

use App\Api;
use App\Exceptions\BinaryLaneException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Http;
use LaravelZero\Framework\Commands\Command;

class Account extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'account';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Show BinaryLane account information';

    /**
     * Execute the console command.
     */
    public function handle(Api $api)
    {
        try
        {
            $account = $api->account();

            $this->table(
                ['Email', 'Status'],
                [[$account['email'], $account['status']]]
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
