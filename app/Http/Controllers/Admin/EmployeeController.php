<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Concerns\HandlesEmployeeBulkUpload;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreEmployeeRequest;
use App\Http\Requests\UpdateEmployeeRequest;
use App\Models\Announcement;
use App\Models\AnnouncementNotification;
use App\Models\Department;
use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\EmployeeAccountService;
use App\Services\SupabaseAuthSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\View\View;

class EmployeeController extends Controller
{
    use HandlesEmployeeBulkUpload;

    /**
     * Clear the bulk upload history. Employee records are not touched.
     */
    public function clearBulkUploadHistory(): RedirectResponse
    {
        $deleted = $this->bulkUploadLogQuery()->delete();
        $entryLabel = $deleted === 1 ? 'entry' : 'entries';

        app(AuditLogService::class)->record('DELETE', 'Employees', "Cleared {$deleted} employee bulk upload history {$entryLabel}.", 'Success', [
            'deleted_upload_entries' => $deleted,
            'employee_records_affected' => false,
        ]);

        return redirect()->route('admin.employees.index')
            ->with('success', $deleted > 0
                ? "Cleared {$deleted} employee bulk upload history {$entryLabel}. Employee records were not changed."
                : 'Bulk upload history is already clear. Employee records were not changed.');
    }

    public function create(): View
    {
        Department::ensureConfiguredSchoolsExist();

        return view('admin.employees.create', array_merge([
            'departments' => Department::query()->facultySchools()->orderBy('name')->get(),
        ], $this->formOptions()));
    }

    public function store(StoreEmployeeRequest $request): RedirectResponse
    {
        $payload = $request->validated();
        $payload = $this->applyDefaultDepartmentForNonTeachingRoles($payload);
        $payload['status'] = 'active';

        try {
            $employee = Employee::create($payload);
        } catch (\Illuminate\Database\UniqueConstraintViolationException $exception) {
            app(AuditLogService::class)->record(
                'CREATE',
                'Employees',
                'Failed to create employee. The employee ID or email already exists.',
                'Failed',
                ['employee_id' => $payload['employee_id'] ?? null, 'email' => $payload['email'] ?? null]
            );

            return back()
                ->withInput()
                ->with('error', 'Failed to create employee. The employee ID or email already exists.');
        }

        [$tempPassword] = $this->provisionEmployeeAccount($employee);
        $emailStatus = $this->sendCredentialsEmail($employee, $tempPassword, isResend: false);

        app(AuditLogService::class)->record(
            'CREATE',
            'Employees',
            'Created employee '.$employee->full_name.' ('.$employee->employee_id.').',
            'Success',
            [
                'employee_id' => $employee->employee_id,
                'email' => $employee->email,
                'credentials_email_sent' => $emailStatus['sent'],
            ]
        );

        return redirect()
            ->route('admin.employees.index')
            ->with('success', 'Employee created successfully.')
            ->with('credential_notice', [
                'employee_name' => $employee->full_name,
                'employee_id' => $employee->employee_id,
                'email' => $employee->email,
                'temp_password' => $tempPassword,
                'email_status' => $emailStatus,
            ]);
    }

    public function index(Request $request): View
    {
        $search = $request->string('search')->toString();
        $departmentId = $request->string('department_id')->toString();
        $employeeClass = $request->string('employee_class')->toString() ?: 'all';
        $status = $request->string('status')->toString() ?: 'all';
        $position = $request->string('position')->toString();

        $employeesQuery = Employee::query()
            ->with(['department'])
            ->when($search, function ($query, $searchTerm) {
                $query->where(function ($nested) use ($searchTerm) {
                    $nested
                        ->where('employee_id', 'like', "%{$searchTerm}%")
                        ->orWhere('first_name', 'like', "%{$searchTerm}%")
                        ->orWhere('last_name', 'like', "%{$searchTerm}%")
                        ->orWhere('email', 'like', "%{$searchTerm}%");
                });
            })
            ->orderBy('last_name')
            ->orderBy('first_name');

        if ($departmentId === 'asp') {
            $employeesQuery->where(function ($query) {
                $query->where('employment_type', 'Admin Support Personnel');
            });
        } elseif (filled($departmentId)) {
            $employeesQuery->where('department_id', $departmentId);
        }

        if ($status !== 'all') {
            $employeesQuery->where('status', $status);
        }

        if (filled($position)) {
            $employeesQuery->where('position', 'like', '%'.$position.'%');
        }

        if ($employeeClass === 'regular') {
            $employeesQuery->where('employment_status', 'regular');
        } elseif ($employeeClass === 'irregular') {
            $employeesQuery->where(function ($query) {
                $query->where('employment_status', 'probationary')
                    ->orWhereNull('employment_status');
            });
        }

        $employees = $employeesQuery->paginate(15)->withQueryString();

        return view('admin.employees.index', [
            'employees' => $employees,
            'latestBulkUploads' => $this->latestBulkUploads(),
            'departments' => Department::query()->orderBy('name')->get(),
            'filters' => [
                'search' => $search,
                'department_id' => $departmentId,
                'employee_class' => $employeeClass,
                'status' => $status,
                'position' => $position,
            ],
        ]);
    }

    public function edit(Employee $employee): View
    {
        Department::ensureConfiguredSchoolsExist();

        return view('admin.employees.edit', array_merge([
            'employee' => $employee,
            'departments' => Department::query()->orderBy('name')->get(),
        ], $this->formOptions()));
    }

    public function show(Employee $employee): View
    {
        return view('admin.employees.show', [
            'employee' => $employee->load('department'),
        ]);
    }

    public function update(UpdateEmployeeRequest $request, Employee $employee): RedirectResponse
    {
        $payload = $request->validated();
        $payload = $this->applyDefaultDepartmentForNonTeachingRoles($payload);
        $previousEmail = $employee->email;
        $wasRegular = $employee->employment_status === 'regular';
        $employee->update($payload);

        $user = User::query()->where('email', $previousEmail)->first();

        if ($user) {
            $user->forceFill(['email' => $employee->email, 'name' => $employee->full_name])->save();
            app(SupabaseAuthSyncService::class)->syncUser($user, null);
        }

        if (! $wasRegular && $employee->employment_status === 'regular' && $user) {
            $announcement = Announcement::create([
                'title' => 'Congratulations on Your Regular Employment Status!',
                'content' => 'Congratulations, '.$employee->full_name.'! Your employment status has been updated to Regular. We appreciate your continued commitment and contributions to NU Lipa.',
                'type' => Announcement::TYPE_NOTIFICATION,
                'priority' => 'high',
                'target_user_id' => $user->id,
                'is_published' => true,
                'published_at' => now(),
                'created_by' => $request->user()->id,
            ]);

            AnnouncementNotification::create([
                'announcement_id' => $announcement->id,
                'user_id' => $user->id,
                'is_read' => false,
                'read_at' => null,
                'redirect_url' => route('employee.notifications'),
            ]);
        }

        app(AuditLogService::class)->record(
            'UPDATE',
            'Employees',
            'Updated employee '.$employee->full_name.' ('.$employee->employee_id.').',
            'Success',
            [
                'employee_id' => $employee->employee_id,
                'fields' => array_keys($payload),
            ]
        );

        return redirect()->route('admin.employees.index')->with('success', 'Employee updated successfully.');
    }

    public function destroy(Employee $employee): RedirectResponse
    {
        $fullName = $employee->full_name;
        $email = $employee->email;
        $employeeCode = $employee->employee_id;

        try {
            DB::transaction(function () use ($employee, $email) {
                $user = User::query()->where('email', $email)->first();

                if ($user) {
                    app(SupabaseAuthSyncService::class)->deleteUser($user);
                    $user->delete();
                }

                $employee->forceDelete();
            });
        } catch (\Throwable $exception) {
            Log::error('Failed to delete employee (admin)', ['employee_id' => $employee->id, 'error' => $exception->getMessage()]);

            app(AuditLogService::class)->record(
                'DELETE',
                'Employees',
                'Failed to delete employee '.$fullName.'.',
                'Failed',
                ['employee_id' => $employeeCode, 'email' => $email]
            );

            return redirect()->route('admin.employees.index')->with('error', 'Failed to delete '.$fullName.': '.$exception->getMessage());
        }

        app(AuditLogService::class)->record(
            'DELETE',
            'Employees',
            'Deleted employee '.$fullName.' ('.$employeeCode.').',
            'Success',
            ['employee_id' => $employeeCode, 'email' => $email]
        );

        return redirect()->route('admin.employees.index')->with('success', $fullName.' deleted.');
    }

    public function resendCredentials(Employee $employee): RedirectResponse
    {
        [$tempPassword] = $this->provisionEmployeeAccount($employee, forceReset: true);
        $emailStatus = $this->sendCredentialsEmail($employee, $tempPassword, isResend: true);

        app(AuditLogService::class)->record(
            'RESET',
            'Employees',
            'Regenerated login credentials for '.$employee->full_name.' ('.$employee->employee_id.').',
            'Success',
            [
                'employee_id' => $employee->employee_id,
                'email' => $employee->email,
                'credentials_email_sent' => $emailStatus['sent'],
            ]
        );

        return redirect()->route('admin.employees.index')->with('success', 'Credentials regenerated for '.$employee->full_name)->with('credential_notice', [
            'employee_name' => $employee->full_name,
            'employee_id' => $employee->employee_id,
            'email' => $employee->email,
            'temp_password' => $tempPassword,
            'is_resend' => true,
            'email_status' => $emailStatus,
        ]);
    }

    private function sendCredentialsEmail(Employee $employee, string $tempPassword, bool $isResend): array
    {
        return app(EmployeeAccountService::class)->sendCredentials($employee, $tempPassword, $isResend);
    }

    private function provisionEmployeeAccount(Employee $employee, bool $forceReset = false): array
    {
        return [app(EmployeeAccountService::class)->provision($employee, $forceReset)];
    }

    /**
     * Shared dropdown options for the employee edit screen.
     *
     * @return array<string,mixed>
     */
    private function formOptions(): array
    {
        $facultyPositions = array_values(config('hris.faculty_positions', []));
        $aspPositions = array_values(config('hris.admin_support_offices', []));

        return [
            'employmentTypes' => config('hris.employment_types', []),
            'facultyPositions' => $facultyPositions,
            'aspPositions' => $aspPositions,
            'employeePositions' => array_values(array_unique(array_merge($facultyPositions, $aspPositions))),
            'facultyRankings' => config('hris.faculty_rankings', []),
        ];
    }

    private function applyDefaultDepartmentForNonTeachingRoles(array $payload): array
    {
        return Employee::withDefaultDepartment($payload);
    }

}
