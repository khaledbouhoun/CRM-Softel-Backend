<?php

namespace App\Console\Commands;

use App\Services\SchedulingService;
use Illuminate\Console\Command;

class ReleaseExpiredHolds extends Command
{
    protected $signature = 'crm:release-expired-holds';

    protected $description = 'Release appointment holds whose expiry time has passed';

    public function handle(SchedulingService $scheduling): int
    {
        $this->info('Released '.$scheduling->releaseExpired().' expired hold(s).');

        return self::SUCCESS;
    }
}
