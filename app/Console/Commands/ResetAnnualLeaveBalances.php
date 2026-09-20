<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use App\Models\Employee;
use App\Services\LeaveBalanceService;

class ResetAnnualLeaveBalances extends Command
{
    protected $signature = 'leaves:reset-annual';
    protected $description = 'Reset annual leave balances (vacation, sick, emergency) on employee hiring anniversaries';

    public function handle(): int
    {
        $today = now();
        $processed = 0;

        Employee::query()
            ->whereNotNull('hire_date')
            ->each(function (Employee $employee) use ($today, &$processed): void {
                $hireDate = $employee->hire_date;

                if ($hireDate->month !== $today->month || $hireDate->day !== $today->day) {
                    return;
                }

                app(LeaveBalanceService::class)->initializeOrUpdateBalance($employee);
                $processed++;
            });

        $this->info("Refreshed leave balances for {$processed} employee anniversary(ies).");

        return self::SUCCESS;
    }
}
