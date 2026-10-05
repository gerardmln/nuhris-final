<?php

namespace Tests\Feature\Admin;

use App\Mail\TimekeeperCredentialsMail;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_create_a_timekeeper_without_an_employee_record(): void
    {
        Mail::fake();

        $admin = User::factory()->create(['user_type' => User::TYPE_ADMIN]);

        $response = $this->withSession(['privacy_notice_acknowledged_version' => config('privacy.notice_version')])
            ->actingAs($admin)->post(route('admin.roles.timekeepers.store'), [
                'email' => 'timekeeper@example.com',
            ]);

        $response->assertRedirect(route('admin.roles.index'));
        $response->assertSessionHas('success', 'Timekeeper account created and credentials sent to timekeeper@example.com.');

        $timekeeper = User::query()->where('email', 'timekeeper@example.com')->firstOrFail();

        $this->assertSame(User::TYPE_HR, $timekeeper->user_type);
        $this->assertDatabaseMissing('employees', ['email' => 'timekeeper@example.com']);
        Mail::assertSent(TimekeeperCredentialsMail::class, function (TimekeeperCredentialsMail $mail) use ($timekeeper) {
            return $mail->user->is($timekeeper)
                && $mail->hasTo('timekeeper@example.com')
                && $mail->temporaryPassword !== '';
        });
    }

    public function test_duplicate_timekeeper_email_is_rejected(): void
    {
        $admin = User::factory()->create(['user_type' => User::TYPE_ADMIN]);
        User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->withSession(['privacy_notice_acknowledged_version' => config('privacy.notice_version')])
            ->actingAs($admin)->from(route('admin.roles.index'))->post(route('admin.roles.timekeepers.store'), [
                'email' => 'existing@example.com',
            ]);

        $response->assertRedirect(route('admin.roles.index'));
        $response->assertSessionHasErrors('email');
    }
}
