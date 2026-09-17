<?php

namespace Tests\Feature\Admin;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_and_filter_audit_logs(): void
    {
        $admin = User::factory()->create([
            'name' => 'Audit Admin',
            'user_type' => User::TYPE_ADMIN,
        ]);

        AdminAuditLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'CREATE',
            'module' => 'Employees',
            'description' => 'Created employee Jane Doe.',
            'status' => 'Success',
            'metadata' => ['ip' => '127.0.0.1'],
        ]);

        AdminAuditLog::query()->create([
            'user_id' => $admin->id,
            'action' => 'LOGIN',
            'module' => 'Authentication',
            'description' => 'Failed login attempt for missing@example.com.',
            'status' => 'Failed',
        ]);

        $response = $this->actingAs($admin)->get(route('admin.integration.audit', [
            'search' => 'Jane Doe',
            'action' => 'CREATE',
            'module' => 'Employees',
            'role' => 'admin',
        ]));

        $response->assertOk();
        $response->assertSee('Audit Logs');
        $response->assertSee('Created employee Jane Doe.');
        $response->assertDontSee('Failed login attempt for missing@example.com.');
    }

    public function test_failed_login_is_recorded_without_the_password(): void
    {
        $user = User::factory()->create([
            'email' => 'staff@example.com',
            'user_type' => User::TYPE_EMPLOYEE,
        ]);

        $this->post('/login', [
            'email' => $user->email,
            'password' => 'wrong-password',
        ]);

        $this->assertGuest();
        $this->assertDatabaseHas('admin_audit_logs', [
            'user_id' => $user->id,
            'action' => 'LOGIN',
            'module' => 'Authentication',
            'status' => 'Failed',
        ]);

        $log = AdminAuditLog::query()->first();

        $this->assertStringNotContainsString('wrong-password', $log->description);
        $this->assertArrayNotHasKey('password', $log->metadata ?? []);
    }
}
