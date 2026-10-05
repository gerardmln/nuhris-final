<?php

namespace App\Http\Controllers\Hr;

use App\Http\Controllers\Controller;
use App\Http\Requests\StoreAnnouncementRequest;
use App\Models\Department;
use App\Models\Announcement;
use App\Models\AnnouncementNotification;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;

class AnnouncementController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->string('search')->toString();
        $priority = $request->string('priority')->toString();
        $facultyPositions = array_values(config('hris.faculty_positions', []));
        $adminSupportOffices = array_values(config('hris.admin_support_offices', []));
        $facultyRankings = array_values(config('hris.faculty_rankings', []));

        $officialAnnouncementsQuery = Announcement::query()
            ->where(function ($query) {
                $query->whereNull('target_employee_type')
                    ->orWhereIn('target_employee_type', ['faculty', 'admin_support']);
            });

        $announcements = (clone $officialAnnouncementsQuery)
            ->latest()
            ->when($search, function ($query, $term) {
                $query->where(function ($nested) use ($term) {
                    $nested->where('title', 'like', "%{$term}%")
                        ->orWhere('content', 'like', "%{$term}%")
                        ->orWhere('priority', 'like', "%{$term}%");
                });
            })
            ->when($priority, fn ($query, $value) => $query->where('priority', $value))
            ->paginate(10)
            ->withQueryString();

        return view('hr.announcements', [
            'announcements' => $announcements,
            'facultyDepartments' => Department::query()->facultySchools()->orderBy('name')->get(),
            'employees' => Employee::query()
                ->whereNotIn('status', ['resigned', 'terminated'])
                ->orderBy('last_name')
                ->orderBy('first_name')
                ->get(['employee_id', 'first_name', 'last_name']),
            'facultyPositions' => $facultyPositions,
            'adminSupportOffices' => $adminSupportOffices,
            'facultyRankings' => $facultyRankings,
            'filters' => [
                'search' => $search,
                'priority' => $priority,
            ],
            'stats' => [
                'total' => (clone $officialAnnouncementsQuery)->count(),
                'active' => (clone $officialAnnouncementsQuery)->where('is_published', true)->count(),
                'high' => (clone $officialAnnouncementsQuery)->where('priority', 'high')->count(),
                'current_month' => (clone $officialAnnouncementsQuery)->whereYear('created_at', now()->year)
                    ->whereMonth('created_at', now()->month)
                    ->count(),
            ],
        ]);
    }

    public function store(StoreAnnouncementRequest $request): RedirectResponse
    {
        DB::transaction(function () use ($request): void {
            $data = $request->validated();
            unset($data['target_scope'], $data['target_employee']);
            unset($data['target_user_id']);

            if ($request->input('target_scope') === 'person') {
                $data['target_user_id'] = $request->input('target_user_id');
                $data['target_employee_type'] = null;
                $data['target_office'] = null;
                $data['target_department_id'] = null;
                $data['target_ranking'] = null;
            } elseif ($request->input('target_scope') === 'all') {
                $data['target_employee_type'] = null;
                $data['target_office'] = null;
                $data['target_department_id'] = null;
                $data['target_ranking'] = null;
            }

            $announcement = Announcement::create([
                ...$data,
                'is_published' => true,
                'created_by' => $request->user()->id,
                'published_at' => now(),
            ]);

            $userQuery = User::query()->where('user_type', User::TYPE_EMPLOYEE);

            if ($announcement->target_user_id) {
                $userQuery->whereKey($announcement->target_user_id);
            }

            $hasEmployeeFilters = filled($announcement->target_employee_type)
                || filled($announcement->target_office)
                || filled($announcement->target_department_id)
                || filled($announcement->target_ranking);

            if (! $announcement->target_user_id && $hasEmployeeFilters) {
                $userQuery->whereHas('employeeProfile');
            }

            if (! $announcement->target_user_id && $announcement->target_employee_type) {
                $employeeType = $announcement->target_employee_type === 'faculty'
                    ? 'Faculty'
                    : 'Admin Support Personnel';

                $userQuery->whereHas('employeeProfile', function ($query) use ($employeeType): void {
                    $query->where('employment_type', $employeeType);
                });
            }

            if (! $announcement->target_user_id && $announcement->target_office) {
                $userQuery->whereHas('employeeProfile', function ($query) use ($announcement): void {
                    $query->where('position', $announcement->target_office);
                });
            }

            if (! $announcement->target_user_id && $announcement->target_department_id && $announcement->target_employee_type === 'faculty') {
                $userQuery->whereHas('employeeProfile', function ($query) use ($announcement): void {
                    $query->where('department_id', $announcement->target_department_id);
                });
            }

            if (! $announcement->target_user_id && $announcement->target_ranking) {
                $userQuery->whereHas('employeeProfile', function ($query) use ($announcement): void {
                    $query->where('ranking', $announcement->target_ranking);
                });
            }

            $userIds = $userQuery->pluck('id');

            $rows = $userIds->map(fn ($userId) => [
                'announcement_id' => $announcement->id,
                'user_id' => $userId,
                'is_read' => false,
                'read_at' => null,
                'redirect_url' => route('employee.notifications'),
                'created_at' => now(),
                'updated_at' => now(),
            ])->all();

            if (! empty($rows)) {
                AnnouncementNotification::insert($rows);
            }
        });

        return redirect()->route('announcements.index')->with('success', 'Announcement posted and notifications sent.');
    }

    public function destroy(Announcement $announcement): RedirectResponse
    {
        $announcement->delete();

        return redirect()->route('announcements.index')->with('success', 'Announcement deleted successfully.');
    }
}
