<?php

namespace Tests\Feature\Employee;

use App\Models\User;
use App\Services\SupabaseAuthSyncService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class PasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    public function test_password_is_not_changed_when_configured_supabase_sync_fails(): void
    {
        $user = User::factory()->create([
            'user_type' => User::TYPE_EMPLOYEE,
        ]);
        $originalHash = $user->password;

        $this->mock(SupabaseAuthSyncService::class, function ($mock): void {
            $mock->shouldReceive('isConfigured')->once()->andReturnTrue();
            $mock->shouldReceive('updateUserPassword')->once()->andReturnFalse();
        });

        $response = $this->withSession([
            'privacy_notice_acknowledged_version' => '1.0',
        ])->actingAs($user)->from(route('employee.profile'))->post(route('employee.profile.change-password'), [
            'current_password' => 'password',
            'new_password' => 'new-password',
            'new_password_confirmation' => 'new-password',
        ]);

        $response
            ->assertSessionHasErrors('new_password')
            ->assertSessionHas('password_error', true)
            ->assertRedirect('/employee/profile');

        $this->assertSame($originalHash, $user->refresh()->password);
        $this->assertFalse(Hash::check('new-password', $user->password));
    }
}