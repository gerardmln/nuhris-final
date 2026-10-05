<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarEntry;
use App\Models\AdminAuditLog;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeScheduleSubmission;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WfhMonitoringSubmission;
use Carbon\Carbon;
use Illuminate\View\View;

class DashboardController extends Controller
{
    public function index(): View
    {
        // Core stats
        $totalEmployees = Employee::query()->count();
        
        $pendingScheduleApprovals = EmployeeScheduleSubmission::query()
            ->where('status', EmployeeScheduleSubmission::STATUS_PENDING)
            ->count();

        $pendingWfhReviews = WfhMonitoringSubmission::query()
            ->where('status', WfhMonitoringSubmission::STATUS_PENDING)
            ->count();

        $pendingLeaveApprovals = LeaveRequest::query()
            ->where('status', 'pending')
            ->count();

        $recentAuditLogsCount = AdminAuditLog::query()
            ->where('created_at', '>=', now()->subDays(30))
            ->count();

        $today = now()->startOfDay();
        $nextCutoffDate = $today->day <= 15
            ? $today->copy()->day(15)
            : $today->copy()->endOfMonth();
        $daysUntilCutoff = max($today->diffInDays($nextCutoffDate, false), 0);

        $actionRequiredCards = [
            [
                'title' => 'Schedule Approvals',
                'count' => $pendingScheduleApprovals,
                'description' => 'Schedule submissions waiting for review.',
                'href' => route('admin.schedules.index'),
                'tone' => 'blue',
                'empty_label' => 'No schedules pending review',
            ],
            [
                'title' => 'WFH Reviews',
                'count' => $pendingWfhReviews,
                'description' => 'WFH submissions waiting for approval.',
                'href' => route('admin.wfh-monitoring.index'),
                'tone' => 'emerald',
                'empty_label' => 'No WFH submissions pending',
            ],
            [
                'title' => 'Leave Approvals',
                'count' => $pendingLeaveApprovals,
                'description' => 'Leave requests waiting for review.',
                'href' => route('admin.leave.index'),
                'tone' => 'slate',
                'empty_label' => 'No leave requests pending',
            ],
            [
                'title' => 'Audit Logs',
                'count' => $recentAuditLogsCount,
                'description' => 'Open audit logs to review recent system activity.',
                'href' => route('admin.integration.audit'),
                'tone' => 'amber',
                'empty_label' => 'No audit logs recorded in the last 30 days',
            ],
            [
                'title' => 'Days to DTR Cutoff',
                'count' => $daysUntilCutoff,
                'description' => sprintf('Remind timekeeper to submit DTR before %s.', $nextCutoffDate->format('M d')),
                'href' => route('admin.dtr.index'),
                'tone' => 'blue',
                'empty_label' => sprintf('Remind timekeeper to submit DTR before %s.', $nextCutoffDate->format('M d')),
            ],
        ];

        $academicCalendarEntries = AcademicCalendarEntry::query()
            ->upcoming()
            ->orderBy('event_date')
            ->limit(3)
            ->get();

        // Recent activities
        $recentActivities = [
            'Admin module initialized successfully',
            $totalEmployees . ' employees in system',
            'Dashboard loaded at ' . Carbon::now()->format('Y-m-d H:i:s'),
        ];

        return view('admin.dashboard', [
            'stats' => [
                'total_employees' => $totalEmployees,
            ],
            'actionRequiredCards' => $actionRequiredCards,
            'academicCalendarEntries' => $academicCalendarEntries,
            'recordsOverview' => [
                ['label' => 'Total Employees', 'value' => $totalEmployees],
                ['label' => 'Audit Logs (30 days)', 'value' => $recentAuditLogsCount],
            ],
            'recentActivities' => $recentActivities,
        ]);
    }
}
