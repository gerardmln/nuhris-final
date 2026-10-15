<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class RoleManagementTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{int, array<string, bool>}>
     */
    public static function portalAccessProvider(): array
    {
        return [
            'admin opens every portal' => [User::TYPE_ADMIN, ['admin.dashboard' => true, 'dashboard' => true, 'employee.dashboard' => true]],
            'hr opens hr and employee portals' => [User::TYPE_HR, ['admin.dashboard' => false, 'dashboard' => true, 'employee.dashboard' => true]],
            'employee opens only the employee portal' => [User::TYPE_EMPLOYEE, ['admin.dashboard' => false, 'dashboard' => false, 'employee.dashboard' => true]],
        ];
    }

    #[DataProvider('portalAccessProvider')]
    public function test_portal_access_follows_the_role_hierarchy(int $userType, array $expectations): void
    {
        $user = User::factory()->create(['user_type' => $userType]);
        $session = ['privacy_notice_acknowledged_version' => config('privacy.notice_version')];

        foreach ($expectations as $route => $allowed) {
            $status = $this->withSession($session)->actingAs($user)->get(route($route))->getStatusCode();

            $allowed
                ? $this->assertLessThan(400, $status, "Expected {$route} to be accessible.")
                : $this->assertSame(403, $status, "Expected {$route} to be forbidden.");
        }
    }

    public function test_accessible_portals_are_listed_for_the_portal_switcher(): void
    {
        $portalRoutes = fn (int $type) => array_column(User::factory()->make(['user_type' => $type])->accessiblePortals(), 'route');

        $this->assertSame(['admin.dashboard', 'dashboard', 'employee.dashboard'], $portalRoutes(User::TYPE_ADMIN));
        $this->assertSame(['dashboard', 'employee.dashboard'], $portalRoutes(User::TYPE_HR));
        $this->assertSame(['employee.dashboard'], $portalRoutes(User::TYPE_EMPLOYEE));
    }
}
