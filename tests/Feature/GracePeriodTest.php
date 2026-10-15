<?php

namespace Tests\Feature;

use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeScheduleDay;
use App\Models\EmployeeScheduleSubmission;
use App\Models\User;
use App\Services\EmployeeScheduleService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class GracePeriodTest extends TestCase
{
    use RefreshDatabase;

    /**
     * @return array<string, array{string, int}>
     */
    public static function arrivalProvider(): array
    {
        return [
            'on time' => ['07:00', 0],
            'within grace period' => ['07:12', 0],
            'last minute of grace period' => ['07:15', 0],
            'one minute past grace period' => ['07:16', 1],
            'well past grace period' => ['07:45', 30],
        ];
    }

    #[DataProvider('arrivalProvider')]
    public function test_tardiness_starts_after_the_fifteen_minute_grace_period(string $timeIn, int $expectedTardiness): void
    {
        $employee = Employee::query()->create([
            'first_name' => 'Test',
            'last_name' => 'Employee',
            'email' => 'employee@example.com',
            'department_id' => Department::query()->create(['name' => 'SACE'])->id,
            'position' => 'Instructor',
        ]);

        $submission = EmployeeScheduleSubmission::query()->create([
            'employee_id' => $employee->id,
            'submitted_by' => User::factory()->create()->id,
            'semester_label' => '1st Term',
            'academic_year' => '2026-2027',
            'status' => EmployeeScheduleSubmission::STATUS_APPROVED,
            'submitted_at' => now(),
            'is_current' => true,
        ]);

        // 2026-10-12 is a Monday.
        EmployeeScheduleDay::query()->create([
            'schedule_submission_id' => $submission->id,
            'day_name' => 'Monday',
            'day_index' => 1,
            'has_work' => true,
            'time_in' => '07:00',
            'time_out' => '19:00',
        ]);

        $evaluation = app(EmployeeScheduleService::class)
            ->evaluateDailyRecord($employee, Carbon::parse('2026-10-12'), $timeIn, '19:00');

        $this->assertSame('present', $evaluation['status']);
        $this->assertSame($expectedTardiness, $evaluation['tardiness_minutes']);
    }
}
