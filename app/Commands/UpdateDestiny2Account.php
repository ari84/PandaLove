<?php

namespace PandaLove\Commands;

use Illuminate\Contracts\Bus\SelfHandling;
use Onyx\Account;
use Onyx\Destiny2\Client;

class UpdateDestiny2Account extends Command implements SelfHandling
{
    private $account;

    /**
     * Create a new command instance.
     *
     * @param \Onyx\Account $account
     */
    public function __construct(Account $account)
    {
        $this->account = $account;
    }

    /**
     * Execute the command.
     *
     * @return void
     */
    public function handle()
    {
        $client = new Client();

        \DB::transaction(function () use ($client) {
            $client->updateAccount($this->account);
        });
    }
}
