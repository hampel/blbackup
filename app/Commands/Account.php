<?php

namespace App\Commands;

use Illuminate\Console\Scheduling\Schedule;

class Account extends BaseCommand
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

    protected string $commandContext = 'account';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $account = $this->api->account();

        $this->table(
            ['Email', 'Status'],
            [[$account['email'], $account['status']]]
        );

        return self::SUCCESS;
    }
}
