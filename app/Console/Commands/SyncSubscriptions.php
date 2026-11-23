<?php

namespace App\Console\Commands;

use App\Services\BillingService;
use Illuminate\Console\Command;

class SyncSubscriptions extends Command
{
    protected $signature = 'billing:sync-subscriptions';

    protected $description = 'Flag maintenance subscriptions whose expiry date has passed';

    public function handle(BillingService $billing): int
    {
        $this->info('Expired '.$billing->syncExpired().' maintenance subscription(s).');

        return self::SUCCESS;
    }
}
