<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Employee;
use App\Services\LeaveBalanceService;

class ResetAnnualLeaveBalances extends Command
{
    protected $signature = 'leaves:reset-annual';
    protected $description = 'Reset annual leave usage on January 1 and refresh employee balances';

    public function handle(): int
    {
        $today = now();

        if ($today->month !== 1 || $today->day !== 1) {
            $this->info('Annual leave usage resets on January 1 only.');
            return self::SUCCESS;
        }

        $processed = 0;

        Employee::query()
            ->each(function (Employee $employee) use (&$processed): void {
                app(LeaveBalanceService::class)->initializeOrUpdateBalance($employee);
                $processed++;
            });

        $this->info("Reset annual leave usage and refreshed balances for {$processed} employee(s).");

        return self::SUCCESS;
    }
}
