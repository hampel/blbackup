<?php

namespace App\Commands;

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
        $account = $this->binarylane->account()->get();

        $this->table(
            ['Email', 'Status'],
            // the raw status when the client has no case for it: a status added to
            // the API since the client was released is still worth showing, and a
            // blank cell would read as the account having none
            [[$account->email, $account->status?->value ?? (string) ($account->raw['status'] ?? '')]]
        );

        return self::SUCCESS;
    }
}
