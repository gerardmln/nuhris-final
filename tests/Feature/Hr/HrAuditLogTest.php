<?php

namespace Tests\Feature\Hr;

use App\Models\AdminAuditLog;
use App\Models\Announcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HrAuditLogTest extends TestCase
{
    use RefreshDatabase;

    private function actingAsHr(User $hr): static
    {
        return $this->withSession(['privacy_notice_acknowledged_version' => config('privacy.notice_version')])
            ->actingAs($hr);
    }

    public function test_hr_announcement_actions_are_logged_under_the_acting_user(): void
    {
        $hr = User::factory()->create(['user_type' => User::TYPE_HR, 'name' => 'Maria Santos']);

        $this->actingAsHr($hr)->post(route('announcements.store'), [
            'title' => 'Payroll Cut-off',
            'content' => 'Submit DTR before Friday.',
            'priority' => 'high',
            'target_scope' => 'all',
            'expires_at' => now()->addWeek()->toDateString(),
        ])->assertRedirect(route('announcements.index'));

        $announcement = Announcement::query()->where('title', 'Payroll Cut-off')->firstOrFail();

        $this->actingAsHr($hr)->delete(route('announcements.destroy', $announcement))
            ->assertRedirect(route('announcements.index'));

        $logs = AdminAuditLog::query()->where('module', 'Announcements')->orderBy('id')->get();

        $this->assertSame(['CREATE', 'DELETE'], $logs->pluck('action')->all());
        $this->assertTrue($logs->every(fn (AdminAuditLog $log) => $log->user_id === $hr->id));
        $this->assertSame('Posted announcement "Payroll Cut-off".', $logs->first()->description);
    }

    public function test_admin_working_in_the_hr_portal_is_logged_as_themselves(): void
    {
        $admin = User::factory()->create(['user_type' => User::TYPE_ADMIN]);

        $this->actingAsHr($admin)->post(route('biometrics.clear'))->assertRedirect();

        $log = AdminAuditLog::query()->where('module', 'DTR')->sole();

        $this->assertSame('DELETE', $log->action);
        $this->assertSame($admin->id, $log->user_id);
        $this->assertSame('Admin', $log->roleLabel());
    }
}
