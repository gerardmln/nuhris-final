<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\EmployeeScheduleSubmission;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Support\Collection;

class LeaveBalanceService
{
    /**
     * Leave types that affect the remaining balance (deductible).
     * All others are tracked but not deducted.
     */
    private const DEDUCTIBLE_LEAVE_TYPES = [
        'Vacation Leave',
        'Sick Leave',
        'Emergency Leave',
    ];

    /**
     * Base leave credits for each employment type.
     */
    private const BASE_CREDITS = [
        'faculty' => [
            'Vacation Leave' => 11,
            'Sick Leave' => 11,
            'Emergency Leave' => 3,
        ],
        'asp' => [
            'Vacation Leave' => 9,
            'Sick Leave' => 9,
            'Emergency Leave' => 3,
        ],
        'part_time_faculty' => [
            'Vacation Leave' => 9,
            'Sick Leave' => 9,
            'Emergency Leave' => 3,
        ],
        // Fixed per term; resets when schedules are reset (see startNewLeaveTerm).
        'contractual_faculty' => [
            'Vacation Leave' => 3,
            'Sick Leave' => 3,
            'Emergency Leave' => 3,
        ],
    ];

    /**
     * Maximum leave credits (except EL which is fixed).
     */
    private const MAX_CREDITS = [
        'Vacation Leave' => 15,
        'Sick Leave' => 15,
        'Emergency Leave' => 3, // Fixed, never increments
    ];

    /**
     * Yearly increment for regular employees with 1+ year service.
     */
    private const YEARLY_INCREMENT = [
        'Vacation Leave' => 2,
        'Sick Leave' => 2,
        'Emergency Leave' => 0, // No increment
    ];

    /**
     * Initialize or update leave balances for an employee.
     * This should be called when:
     * - A new employee is created
     * - An employee's employment type changes
     * - Yearly accrual needs to be processed
     */
    public function initializeOrUpdateBalance(Employee $employee): void
    {
        $employmentType = strtolower(trim($employee->employment_type ?? ''));
        
        // Determine if Faculty or ASP
        $typeKey = $this->determineEmployeeTypeKey($employmentType);
        
        if (!$typeKey) {
            // Unknown employee types should not keep leave balance rows.
            LeaveBalance::query()->where('employee_id', $employee->id)->delete();
            return;
        }

        // Get base credits for this employment type
        $baseCredits = self::BASE_CREDITS[$typeKey];

        // For each deductible leave type, calculate total credits
        foreach ($baseCredits as $leaveType => $baseAmount) {
            // Calculate yearly increments if employee is regular
            $totalCredits = $this->calculateTotalCredits($employee, $leaveType, $baseAmount);

            // Calculate used days from approved leave requests
            $usedDays = $this->calculateUsedDays($employee, $leaveType);

            // Calculate remaining balance
            $remainingDays = max(0, $totalCredits - $usedDays);

            // Update or create balance record
            LeaveBalance::updateOrCreate(
                [
                    'employee_id' => $employee->id,
                    'leave_type' => $leaveType,
                ],
                [
                    'remaining_days' => $remainingDays,
                ]
            );
        }
    }

    /**
     * Determine the employee type key (faculty or asp).
     */
    private function determineEmployeeTypeKey(string $employmentType): ?string
    {
        if (Employee::isContractualFacultyType($employmentType)) {
            return 'contractual_faculty';
        }

        if (str_contains($employmentType, 'part-time') ||
            str_contains($employmentType, 'part time') ||
            str_contains($employmentType, 'parttime')) {
            return 'part_time_faculty';
        }

        if (str_contains($employmentType, 'faculty')) {
            return 'faculty';
        }

        if (str_contains($employmentType, 'asp') || 
            str_contains($employmentType, 'admin support')) {
            return 'asp';
        }

        return null;
    }

    /**
     * Calculate total credits from completed hire anniversaries.
     */
    private function calculateTotalCredits(Employee $employee, string $leaveType, int $baseAmount): float
    {
        $totalCredits = (float) $baseAmount;

        if (
            ! $employee->hire_date
            || $this->isPartTimeEmployee($employee)
            || $employee->isContractualFaculty()
        ) {
            return $totalCredits;
        }

        $increment = self::YEARLY_INCREMENT[$leaveType] ?? 0;
        $maxCredits = self::MAX_CREDITS[$leaveType] ?? PHP_INT_MAX;

        $completeYears = (int) floor(Carbon::parse($employee->hire_date)->diffInYears(now()));
        $totalCredits += $completeYears * $increment;

        if ($totalCredits > $maxCredits) {
            $totalCredits = $maxCredits;
        }

        return $totalCredits;
    }

    public function isPartTimeEmployee(Employee $employee): bool
    {
        $employmentType = strtolower(trim((string) $employee->employment_type));

        return str_contains($employmentType, 'part-time') ||
            str_contains($employmentType, 'part time') ||
            str_contains($employmentType, 'parttime');
    }

    /**
     * Get years of service for an employee.
     */
    private function getYearsOfService(Employee $employee): float
    {
        if (!$employee->hire_date) {
            return 0;
        }

        $hireDate = Carbon::parse($employee->hire_date);
        return $hireDate->diffInYears(now(), true);
    }

    /**
     * Calculate how many days of a specific leave type have been used.
     * Only counts approved leave requests.
     */
    private function calculateUsedDays(Employee $employee, string $leaveType): float
    {
        return (float) LeaveRequest::query()
            ->where('employee_id', $employee->id)
            ->where('leave_type', $leaveType)
            ->where('status', 'approved')
            ->whereDate('start_date', '>=', $this->usagePeriodStart($employee)->toDateString())
            ->sum('days_deducted');
    }

    public function currentCalendarYearStart(): Carbon
    {
        return now()->startOfYear();
    }

    /**
     * Leave used on or after this date counts against the current balance:
     * January 1 for most employees, the current term start for contractual
     * faculty (from their approved schedule's term start date; before their
     * first one, their hire date).
     */
    public function usagePeriodStart(Employee $employee): Carbon
    {
        if (! $employee->isContractualFaculty()) {
            return $this->currentCalendarYearStart();
        }

        $termStart = $employee->leave_term_started_at ?? $employee->hire_date;

        return $termStart ? Carbon::parse($termStart)->startOfDay() : Carbon::create(1970);
    }

    /**
     * Called when a schedule is approved: a contractual faculty member's
     * leave term follows the term start date on their approved schedule.
     *
     * @return bool whether a new leave term started
     */
    public function applyApprovedSchedule(EmployeeScheduleSubmission $submission): bool
    {
        $employee = $submission->employee;

        return $employee && $submission->term_start_date
            && $this->startLeaveTerm($employee, $submission->term_start_date);
    }

    /**
     * Set the start of a contractual faculty member's leave term (the term
     * start date on their approved schedule). Leave dated before it no
     * longer counts, so a new term begins at 3 VL / 3 SL / 3 EL.
     *
     * @return bool whether the employee's leave term changed
     */
    public function startLeaveTerm(Employee $employee, Carbon $termStart): bool
    {
        if (! $employee->isContractualFaculty()
            || $employee->leave_term_started_at?->isSameDay($termStart)) {
            return false;
        }

        $employee->forceFill(['leave_term_started_at' => $termStart->toDateString()])->save();
        $this->initializeOrUpdateBalance($employee);

        return true;
    }

    /**
     * Get comprehensive leave usage breakdown for an employee.
     * Returns all leave types with their usage counts, separated by deductible vs tracked-only.
     */
    public function getLeaveUsageBreakdown(Employee $employee): array
    {
        $allLeaveTypes = [
            'Vacation Leave',
            'Bereavement Leave',
            'Sick Leave',
            'Training Leave',
            'Official Business',
            'Work From Home',
            'Birthday Leave',
            'Emergency Leave',
            'Maternity Leave',
            'Solo Parent Leave',
            'Special Leave for Women',
            'Paternity Leave',
            'Study Leave With Pay',
            'Study Leave Without Pay',
            'Leave Without Pay',
            'Research Leave',
        ];

        $approvedRequests = $employee->leaveRequests()
            ->where('status', 'approved')
            ->get()
            ->groupBy('leave_type');

        $breakdown = [
            'deductible' => [],
            'tracked_only' => [],
        ];

        foreach ($allLeaveTypes as $leaveType) {
            $requests = $approvedRequests->get($leaveType, collect());
            $totalDays = (float) ($this->isDeductibleLeaveType($leaveType)
                ? $requests
                    ->filter(fn (LeaveRequest $request) => $request->start_date?->gte($this->usagePeriodStart($employee)))
                    ->sum('days_deducted')
                : $requests->sum('days_deducted'));
            $count = $requests->count();

            $entry = [
                'type' => $leaveType,
                'days_used' => $totalDays,
                'count' => $count,
            ];

            if (in_array($leaveType, self::DEDUCTIBLE_LEAVE_TYPES, true)) {
                $breakdown['deductible'][] = $entry;
            } else {
                $breakdown['tracked_only'][] = $entry;
            }
        }

        return $breakdown;
    }

    /**
     * Get remaining balance for a specific leave type.
     */
    public function getRemainingBalance(Employee $employee, string $leaveType): float
    {
        $balance = LeaveBalance::where('employee_id', $employee->id)
            ->where('leave_type', $leaveType)
            ->first();

        return $balance ? (float) $balance->remaining_days : 0;
    }

    /**
     * Get all remaining balances for deductible leave types.
     */
    public function getDeductibleLeaveBalances(Employee $employee): Collection
    {
        return $employee->leaveBalances()
            ->whereIn('leave_type', self::DEDUCTIBLE_LEAVE_TYPES)
            ->orderBy('leave_type')
            ->get()
            ->map(fn ($balance) => [
                'type' => $balance->leave_type,
                'remaining' => $balance->remaining_days,
            ]);
    }

    /**
     * Check if a leave type is deductible (affects balance).
     */
    public function isDeductibleLeaveType(string $leaveType): bool
    {
        return in_array($leaveType, self::DEDUCTIBLE_LEAVE_TYPES, true);
    }

    /**
     * Get list of all deductible leave types.
     */
    public static function getDeductibleLeaveTypes(): array
    {
        return self::DEDUCTIBLE_LEAVE_TYPES;
    }

}
