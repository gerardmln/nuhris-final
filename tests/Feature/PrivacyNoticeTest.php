<?php

namespace Tests\Feature;

use App\Models\PrivacyNoticeAcknowledgment;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PrivacyNoticeTest extends TestCase
{
    use RefreshDatabase;

    public function test_unacknowledged_users_are_redirected_before_dashboard_access(): void
    {
        $user = User::factory()->create(['user_type' => User::TYPE_HR]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('privacy.notice'));
        $this->actingAs($user)->get(route('privacy.notice'))->assertOk();
    }

    public function test_user_can_acknowledge_the_current_notice_and_continue(): void
    {
        $user = User::factory()->create(['user_type' => User::TYPE_HR]);

        $response = $this->actingAs($user)->post(route('privacy.notice.acknowledge'), [
            'acknowledged' => '1',
        ]);

        $response->assertRedirect(route('dashboard'));
        $this->assertDatabaseHas('privacy_notice_acknowledgments', [
            'user_id' => $user->id,
            'privacy_notice_version' => config('privacy.notice_version'),
        ]);

        $this->actingAs($user)->get(route('dashboard'))->assertOk();
    }

    public function test_a_previous_database_acknowledgment_does_not_skip_a_new_login(): void
    {
        $user = User::factory()->create(['user_type' => User::TYPE_HR]);
        PrivacyNoticeAcknowledgment::create([
            'user_id' => $user->id,
            'privacy_notice_version' => '1.0',
            'acknowledged_at' => now(),
        ]);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertRedirect(route('privacy.notice'));
    }

    public function test_a_new_notice_version_requires_acknowledgment_in_the_current_session(): void
    {
        $user = User::factory()->create(['user_type' => User::TYPE_HR]);
        $this->actingAs($user)->withSession([
            'privacy_notice_acknowledged_version' => '1.0',
        ]);
        config(['privacy.notice_version' => '2.0']);

        $response = $this->get(route('dashboard'));

        $response->assertRedirect(route('privacy.notice'));
    }

    public function test_user_can_log_out_without_acknowledging_the_notice(): void
    {
        $user = User::factory()->create(['user_type' => User::TYPE_HR]);

        $response = $this->actingAs($user)->post(route('logout'));

        $response->assertRedirect(route('login'));
        $this->assertGuest();
    }
}
