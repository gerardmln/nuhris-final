<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AcademicCalendarEntry;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCredential;
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
        
        // Credentials stats
        $expiringPrc = EmployeeCredential::query()
            ->where('credential_type', 'prc')
            ->where('status', 'verified')
            ->get()
            ->filter(fn (EmployeeCredential $cred) => $cred->isExpiringSoon())
            ->count();
        
        $pendingVerifications = EmployeeCredential::query()
            ->where('status', 'pending')
            ->count();

        $pendingScheduleApprovals = EmployeeScheduleSubmission::query()
            ->where('status', EmployeeScheduleSubmission::STATUS_PENDING)
            ->count();

        $pendingWfhReviews = WfhMonitoringSubmission::query()
            ->where('status', WfhMonitoringSubmission::STATUS_PENDING)
            ->count();

        $pendingLeaveApprovals = LeaveRequest::query()
            ->where('status', 'pending')
            ->count();

        // Compliance rate (credentials verified out of total employees)
        $verifiedCredentials = EmployeeCredential::query()
            ->where('status', 'verified')
            ->distinct('employee_id')
            ->count('employee_id');
        $complianceRate = $totalEmployees > 0 ? round(($verifiedCredentials / $totalEmployees) * 100) : 0;

        $actionRequiredCards = [
            [
                'title' => 'Expiring PRC',
                'count' => $expiringPrc,
                'description' => 'Verified PRC credentials nearing expiration.',
                'href' => route('admin.credentials.index'),
                'tone' => 'amber',
                'empty_label' => 'No expiring PRC credentials',
            ],
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
            $pendingVerifications . ' credentials pending verification',
            $expiringPrc . ' PRC credentials expiring soon',
            'Dashboard loaded at ' . Carbon::now()->format('Y-m-d H:i:s'),
        ];

        return view('admin.dashboard', [
            'stats' => [
                'total_employees' => $totalEmployees,
                'compliance_rate' => $complianceRate,
                'expiring_prc' => $expiringPrc,
                'pending_verifications' => $pendingVerifications,
            ],
            'actionRequiredCards' => $actionRequiredCards,
            'academicCalendarEntries' => $academicCalendarEntries,
            'recordsOverview' => [
                ['label' => 'Total Employees', 'value' => $totalEmployees],
                ['label' => 'Pending Verifications', 'value' => $pendingVerifications],
                ['label' => 'Leaves for Approval', 'value' => $pendingLeaveApprovals],
            ],
            'recentActivities' => $recentActivities,
        ]);
    }
}
