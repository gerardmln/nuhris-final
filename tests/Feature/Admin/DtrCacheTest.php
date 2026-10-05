<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\LeaveRequest;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DtrCacheTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_clear_dtr_upload_cache_without_deleting_attendance_records(): void
    {
        $admin = User::factory()->create([
            'user_type' => User::TYPE_ADMIN,
        ]);

        $department = Department::query()->create([
            'name' => 'Human Resources',
        ]);

        $employee = Employee::query()->create([
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'email' => 'employee@example.com',
            'department_id' => $department->id,
            'position' => 'Staff',
        ]);

        AttendanceRecord::query()->create([
            'employee_id' => $employee->id,
            'record_date' => '2026-10-01',
            'time_in' => '08:00',
            'time_out' => '17:00',
            'status' => 'present',
        ]);

        AdminAuditLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'CREATE',
            'module' => 'DTR',
            'description' => 'Imported DTR PDF.',
            'status' => 'Success',
            'metadata' => ['original_filename' => 'timesheet.pdf'],
        ]);

        $response = $this->withSession([
            'privacy_notice_acknowledged_version' => config('privacy.notice_version'),
        ])->actingAs($admin)->post(route('admin.dtr.clear-cache'));

        $response->assertRedirect(route('admin.dtr.index'));
        $response->assertSessionHas('success', 'Cleared 1 DTR upload cache entry.');
        $this->assertDatabaseCount('attendance_records', 1);
        $this->assertDatabaseMissing('admin_audit_logs', [
            'action' => 'CREATE',
            'module' => 'DTR',
            'description' => 'Imported DTR PDF.',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'DELETE',
            'module' => 'DTR',
            'description' => 'Cleared 1 DTR upload cache entry.',
        ]);
    }

    public function test_admin_can_clear_leave_file_cache_without_deleting_leave_records(): void
    {
        $admin = User::factory()->create([
            'user_type' => User::TYPE_ADMIN,
        ]);

        $department = Department::query()->create([
            'name' => 'Leave Test Department',
        ]);

        $employee = Employee::query()->create([
            'first_name' => 'Leave',
            'last_name' => 'Employee',
            'email' => 'leave.employee@example.com',
            'department_id' => $department->id,
            'position' => 'Staff',
        ]);

        LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'leave_type' => 'Vacation Leave',
            'start_date' => '2026-10-10',
            'end_date' => '2026-10-10',
            'days_deducted' => 1,
            'status' => 'approved',
        ]);

        AdminAuditLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'CREATE',
            'module' => 'Leave Management',
            'description' => 'Imported leave file.',
            'status' => 'Success',
            'metadata' => ['original_filename' => 'leave-applications.xlsx'],
        ]);

        $response = $this->withSession([
            'privacy_notice_acknowledged_version' => config('privacy.notice_version'),
        ])->actingAs($admin)->post(route('admin.leave.clear-cache'));

        $response->assertRedirect(route('admin.leave.index'));
        $response->assertSessionHas('success', 'Cleared 1 leave file cache entry.');
        $this->assertDatabaseCount('leave_requests', 1);
        $this->assertDatabaseMissing('admin_audit_logs', [
            'action' => 'CREATE',
            'module' => 'Leave Management',
            'description' => 'Imported leave file.',
        ]);
        $this->assertDatabaseHas('admin_audit_logs', [
            'action' => 'DELETE',
            'module' => 'Leave Management',
            'description' => 'Cleared 1 leave file cache entry.',
        ]);
    }
}