<?php

namespace Tests\Feature;

use App\Models\AcademicCalendarEntry;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeScheduleDay;
use App\Models\EmployeeScheduleSubmission;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\EmployeeScheduleService;
use App\Services\LeaveBalanceService;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContractualFacultyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake();
    }

    private function employee(string $type, string $employeeId, string $hireDate = '2023-07-04'): Employee
    {
        return Employee::query()->create([
            'employee_id' => $employeeId,
            'first_name' => 'Test',
            'last_name' => $employeeId,
            'email' => $employeeId.'@example.com',
            'department_id' => Department::query()->firstOrCreate(['name' => 'SACE'])->id,
            'position' => 'Instructor',
            'employment_type' => $type,
            'employment_status' => 'probationary',
            'ranking' => 'Instructor 1',
            'hire_date' => $hireDate,
        ]);
    }

    private function balances(Employee $employee): array
    {
        app(LeaveBalanceService::class)->initializeOrUpdateBalance($employee);

        return $employee->leaveBalances()->pluck('remaining_days', 'leave_type')->map(fn ($days) => (float) $days)->all();
    }

    private function as(int $userType): static
    {
        return $this->withSession(['privacy_notice_acknowledged_version' => config('privacy.notice_version')])
            ->actingAs(User::factory()->create(['user_type' => $userType]));
    }

    public function test_adding_contractual_faculty_always_saves_probationary(): void
    {
        $this->as(User::TYPE_HR)->post(route('employees.store'), [
            'email' => 'contractual@example.com',
            'first_name' => 'Cara',
            'last_name' => 'Contract',
            'employment_type' => Employee::TYPE_CONTRACTUAL_FACULTY,
            'employment_status' => 'regular',
            'department_id' => Department::query()->firstOrCreate(['name' => 'SACE'])->id,
            'position' => 'Assistant Professor',
            'ranking' => 'Assistant Professor 2',
            'hire_date' => '2026-08-01',
        ])->assertSessionHasNoErrors();

        $employee = Employee::query()->where('email', 'contractual@example.com')->sole();
        $this->assertSame('probationary', $employee->employment_status);
        $this->assertSame('Assistant Professor', $employee->position);
        $this->assertSame('Assistant Professor 2', $employee->ranking);
        $this->assertNotNull($employee->department_id);
    }

    public function test_leave_credits_are_three_each_with_no_yearly_increase(): void
    {
        $contractual = $this->employee(Employee::TYPE_CONTRACTUAL_FACULTY, '23-0001');
        $faculty = $this->employee('Faculty', '23-0002');

        $this->assertSame(['Emergency Leave' => 3.0, 'Sick Leave' => 3.0, 'Vacation Leave' => 3.0], collect($this->balances($contractual))->sortKeys()->all());
        $this->assertSame(15.0, $this->balances($faculty)['Vacation Leave']); // 11 + 2 per year, capped
    }

    private function leave(Employee $employee, string $date, float $days): void
    {
        LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'leave_type' => 'Vacation Leave',
            'start_date' => $date,
            'end_date' => $date,
            'days_deducted' => $days,
            'status' => 'approved',
        ]);
    }

    private function submission(Employee $employee, string $term, ?string $termStart, string $status = EmployeeScheduleSubmission::STATUS_PENDING): EmployeeScheduleSubmission
    {
        return EmployeeScheduleSubmission::query()->create([
            'employee_id' => $employee->id,
            'submitted_by' => User::factory()->create()->id,
            'semester_label' => $term,
            'term_label' => $term,
            'term_start_date' => $termStart,
            'status' => $status,
            'submitted_at' => now(),
            'is_current' => false,
        ]);
    }

    public function test_employee_schedule_form_asks_contractual_faculty_for_the_term_start(): void
    {
        $contractual = $this->employee(Employee::TYPE_CONTRACTUAL_FACULTY, '24-0001', '2024-08-01');
        $faculty = $this->employee('Faculty', '24-0002', '2024-08-01');
        $days = collect(['monday', 'tuesday', 'wednesday', 'thursday', 'friday', 'saturday'])
            ->mapWithKeys(fn ($day) => [$day => ['mode' => 'with_work', 'time_in' => '07:00', 'time_out' => '17:00']])->all();
        $asEmployee = fn (Employee $employee) => $this->withSession(['privacy_notice_acknowledged_version' => config('privacy.notice_version')])
            ->actingAs(User::query()->where('email', $employee->email)->first()
                ?? User::factory()->create(['email' => $employee->email, 'user_type' => User::TYPE_EMPLOYEE]));

        $asEmployee($contractual)->get(route('employee.attendance'))->assertOk()->assertSee('Term start date');
        $asEmployee($faculty)->get(route('employee.attendance'))->assertOk()->assertDontSee('Term start date');

        $asEmployee($contractual)->post(route('employee.attendance.schedule.store'), ['term_label' => '2nd Term', 'days' => $days])
            ->assertSessionHasErrors('term_start_date');

        $this->post(route('employee.attendance.schedule.store'), ['term_label' => '2nd Term', 'term_start_date' => '2026-10-12', 'days' => $days])
            ->assertSessionHasNoErrors();
        $this->assertSame('2026-10-12', EmployeeScheduleSubmission::query()->where('employee_id', $contractual->id)->sole()->term_start_date->toDateString());
    }

    public function test_approving_the_schedule_starts_the_leave_term_on_its_term_start_date(): void
    {
        foreach ([[User::TYPE_HR, 'schedules.approve'], [User::TYPE_ADMIN, 'admin.schedules.approve']] as $i => [$userType, $route]) {
            $employee = $this->employee(Employee::TYPE_CONTRACTUAL_FACULTY, '24-001'.$i, '2024-08-01');
            $this->leave($employee, '2026-09-23', 1.06); // 1st Term
            $this->leave($employee, '2026-10-12', 1);    // 2nd Term, filed before the schedule was approved
            $this->assertSame(0.94, $this->balances($employee)['Vacation Leave']);

            $submission = $this->submission($employee, '2nd Term', '2026-10-12');
            $this->as($userType)->post(route($route, $submission), ['confirmed' => '1'])
                ->assertSessionHas('success', fn ($message) => str_contains($message, 'Leave term starts Oct 12, 2026'));

            $this->assertSame('2026-10-12', $employee->fresh()->leave_term_started_at->toDateString(), $route);
            $this->assertSame(2.0, $this->balances($employee->fresh())['Vacation Leave'], $route);
        }
    }

    public function test_clearing_or_resetting_a_schedule_does_not_touch_leave(): void
    {
        $employee = $this->employee(Employee::TYPE_CONTRACTUAL_FACULTY, '24-0001', '2024-08-01');
        $this->leave($employee, '2026-10-12', 1);
        $approved = $this->submission($employee, '2nd Term', '2026-10-12');
        $this->as(User::TYPE_HR)->post(route('schedules.approve', $approved), ['confirmed' => '1']);
        $this->assertSame(2.0, $this->balances($employee->fresh())['Vacation Leave']);

        $this->as(User::TYPE_HR)->post(route('schedules.clear', $approved), ['confirmed' => '1'])->assertSessionHas('success');
        $this->as(User::TYPE_ADMIN)->delete(route('admin.schedules.reset-all'))->assertRedirect();

        $this->assertSame('2026-10-12', $employee->fresh()->leave_term_started_at->toDateString());
        $this->assertSame(2.0, $this->balances($employee->fresh())['Vacation Leave']);

        // Resubmitting the same term with the same start date keeps the usage.
        $again = $this->submission($employee, '2nd Term', '2026-10-12');
        $this->as(User::TYPE_HR)->post(route('schedules.approve', $again), ['confirmed' => '1']);
        $this->assertSame(2.0, $this->balances($employee->fresh())['Vacation Leave']);
    }

    public function test_term_start_only_applies_to_contractual_faculty(): void
    {
        $faculty = $this->employee('Faculty', '24-0003', '2024-08-01');
        $submission = $this->submission($faculty, '2nd Term', '2026-10-12');

        $this->as(User::TYPE_HR)->post(route('schedules.approve', $submission), ['confirmed' => '1']);

        $this->assertNull($faculty->fresh()->leave_term_started_at);
    }

    public function test_leave_cards_count_approved_upcoming_leave_as_used(): void
    {
        $employee = $this->employee(Employee::TYPE_CONTRACTUAL_FACULTY, '24-0099', '2024-08-01');
        LeaveRequest::query()->create([
            'employee_id' => $employee->id,
            'leave_type' => 'Vacation Leave',
            'start_date' => now()->addDay()->toDateString(),
            'end_date' => now()->addDay()->toDateString(),
            'days_deducted' => 1,
            'status' => 'approved',
        ]);

        foreach ([[User::TYPE_HR, 'leave.index'], [User::TYPE_ADMIN, 'admin.leave.index']] as [$userType, $route]) {
            $this->as($userType)->get(route($route))->assertOk()->assertViewHas('leaveCards', function ($cards) use ($employee) {
                $card = collect($cards)->firstWhere('id', $employee->id);

                return (float) $card['vacation_used'] === 1.0 && (float) $card['vacation_remaining'] === 2.0;
            });
        }
    }

    public function test_approved_leave_and_academic_holidays_count_as_present_from_day_one(): void
    {
        $contractual = $this->employee(Employee::TYPE_CONTRACTUAL_FACULTY, '26-0001', now()->subMonth()->toDateString());
        $newFaculty = $this->employee('Faculty', '26-0002', now()->subMonth()->toDateString());
        $monday = Carbon::parse('2026-10-12');
        $tuesday = Carbon::parse('2026-10-13');

        AcademicCalendarEntry::query()->create([
            'title' => 'Holiday',
            'entry_type' => 'holiday',
            'day_type' => 'non_working',
            'event_date' => $monday->toDateString(),
        ]);

        foreach ([$contractual, $newFaculty] as $employee) {
            $submission = EmployeeScheduleSubmission::query()->create([
                'employee_id' => $employee->id,
                'submitted_by' => User::factory()->create()->id,
                'semester_label' => '1st Term',
                'academic_year' => '2026-2027',
                'status' => EmployeeScheduleSubmission::STATUS_APPROVED,
                'submitted_at' => now(),
                'is_current' => true,
            ]);

            foreach ([1 => 'Monday', 2 => 'Tuesday'] as $index => $day) {
                EmployeeScheduleDay::query()->create([
                    'schedule_submission_id' => $submission->id,
                    'day_name' => $day,
                    'day_index' => $index,
                    'has_work' => true,
                    'time_in' => '07:00',
                    'time_out' => '19:00',
                ]);
            }

            LeaveRequest::query()->create([
                'employee_id' => $employee->id,
                'leave_type' => 'Sick Leave',
                'start_date' => $tuesday->toDateString(),
                'end_date' => $tuesday->toDateString(),
                'days_deducted' => 1,
                'status' => 'approved',
            ]);

            app(LeaveBalanceService::class)->initializeOrUpdateBalance($employee);
        }

        $evaluate = fn (Employee $employee, Carbon $date) => (new EmployeeScheduleService())->evaluateDailyRecord($employee, $date, null, null);

        $this->assertSame('present', $evaluate($contractual, $monday)['status']);
        $this->assertSame('present', $evaluate($contractual, $tuesday)['status']);
        $this->assertSame('Approved leave: Sick Leave', $evaluate($contractual, $tuesday)['schedule_notes']);

        foreach (['Vacation Leave', 'Emergency Leave'] as $leaveType) {
            LeaveRequest::query()->where('employee_id', $contractual->id)->update(['leave_type' => $leaveType]);
            app(LeaveBalanceService::class)->initializeOrUpdateBalance($contractual);

            $this->assertSame('present', $evaluate($contractual, $tuesday)['status'], $leaveType);
            $this->assertSame('Approved leave: '.$leaveType, $evaluate($contractual, $tuesday)['schedule_notes']);
        }

        // A probationary faculty member with under a year of service does not get these yet.
        $this->assertSame('absent', $evaluate($newFaculty, $monday)['status']);
        $this->assertSame('absent', $evaluate($newFaculty, $tuesday)['status']);
    }
}
