<?php

namespace Tests\Feature;

use App\Mail\EmployeeCredentialsMail;
use App\Models\AdminAuditLog;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class EmployeeBulkUploadTest extends TestCase
{
    use RefreshDatabase;

    private const HEADER = ['Employee ID', 'Email', 'First Name', 'Last Name', 'Employee Type', 'Employment Status', 'Department', 'Rank', 'Level', 'Hire Date'];

    protected function setUp(): void
    {
        parent::setUp();

        Mail::fake();
        Http::fake();
    }

    /**
     * Rows from the sample employee_import_sample.xlsx.
     *
     * @return array<int, array<int, mixed>>
     */
    private function sampleRows(): array
    {
        return [
            ['2508064', 'angelus.malaluan@gmail.com', 'Reymark', 'Natividad', 'Faculty', 'Probationary', 'SACE', 'Assistant Professor', '3', '2025-07-31'],
            ['238041', 'malaluangerard@gmail.com', 'Jei', 'Pastrana', 'Faculty', 'Regular', 'SACE', 'Assistant Professor', '3', '2023-07-04'],
            ['2408130', 'donoharmbygrd@gmail.com', 'Allainer', 'Reyes', 'Part-Time Faculty', 'Probationary', 'SACE', 'Part-Time Faculty', null, '2024-08-08'],
            ['238111', 'pritongtalong808@gmail.com', 'Jerimaiah', 'Padua', 'Admin Support Personnel', 'Regular', null, 'Human Resource Office', null, ExcelDate::stringToExcel('2024-08-11')],
        ];
    }

    /**
     * @param  array<int, array<int, mixed>>  $rows
     */
    private function excelFile(array $rows, string $name = 'employee_import_sample.xlsx'): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Employees');
        $sheet->fromArray([self::HEADER, ...$rows], null, 'A1', true);
        $spreadsheet->createSheet()->setTitle('Guide')->setCellValue('A1', 'Fill in one employee per row.');

        $path = tempnam(sys_get_temp_dir(), 'bulk').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, $name, 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', null, true);
    }

    private function actingAsRole(int $userType): static
    {
        return $this->withSession(['privacy_notice_acknowledged_version' => config('privacy.notice_version')])
            ->actingAs(User::factory()->create(['user_type' => $userType]));
    }

    public function test_hr_bulk_upload_adds_sample_employees_with_accounts(): void
    {
        $response = $this->actingAsRole(User::TYPE_HR)
            ->post(route('employees.bulk-upload'), ['employees_file' => $this->excelFile($this->sampleRows())]);

        $response->assertRedirect();
        $result = session('bulk_upload_result');
        $this->assertCount(4, $result['created'], json_encode($result['failed']));
        $this->assertSame([], $result['failed']);

        $reymark = Employee::query()->where('employee_id', '2508064')->with('department')->sole();
        $this->assertSame('Assistant Professor', $reymark->position);
        $this->assertSame('Assistant Professor 3', $reymark->ranking);
        $this->assertStringStartsWith('SACE', $reymark->department->name);
        $this->assertSame('2025-07-31', $reymark->hire_date->toDateString());

        $padua = Employee::query()->where('employee_id', '238111')->with('department')->sole();
        $this->assertSame('Human Resource Office (HR)', $padua->position);
        $this->assertSame('ASP', $padua->department->name);
        $this->assertSame('2024-08-11', $padua->hire_date->toDateString());

        $this->assertSame('probationary', Employee::query()->where('employee_id', '2408130')->value('employment_status'));

        // Login accounts and credential emails are handled after the response.
        $this->assertSame(4, User::query()->whereIn('email', array_column($this->sampleRows(), 1))->count());
        Mail::assertSent(EmployeeCredentialsMail::class, 4);

        $this->assertDatabaseHas('admin_audit_logs', ['module' => 'Employees', 'action' => 'CREATE', 'metadata->original_filename' => 'employee_import_sample.xlsx']);
    }

    public function test_reuploading_skips_employees_already_registered_with_or_without_dash(): void
    {
        $this->actingAsRole(User::TYPE_ADMIN)
            ->post(route('admin.employees.bulk-upload'), ['employees_file' => $this->excelFile($this->sampleRows())]);

        $dashed = array_map(function (array $row) {
            $row[0] = substr($row[0], 0, 2).'-'.substr($row[0], 2);

            return $row;
        }, $this->sampleRows());

        $this->actingAsRole(User::TYPE_ADMIN)
            ->post(route('admin.employees.bulk-upload'), ['employees_file' => $this->excelFile($dashed, 'again.xlsx')]);

        $result = session('bulk_upload_result');
        $this->assertCount(0, $result['created']);
        $this->assertCount(4, $result['existing']);
        $this->assertSame(4, Employee::query()->count());
    }

    public function test_invalid_rows_are_reported_and_valid_rows_still_imported(): void
    {
        $rows = [
            ...$this->sampleRows(),
            ['2509999', 'not-an-email', 'Bad', 'Email', 'Faculty', 'Regular', 'SACE', 'Instructor', '1', '2025-01-01'],
            ['2509998', 'shs@example.com', 'Wrong', 'Rank', 'Faculty', 'Regular', 'SACE', 'Treasury Office', null, null],
            ['2509997', 'nodept@example.com', 'No', 'Dept', 'Faculty', 'Regular', null, 'Instructor', '2', null],
        ];

        $this->actingAsRole(User::TYPE_HR)
            ->post(route('employees.bulk-upload'), ['employees_file' => $this->excelFile($rows)]);

        $result = session('bulk_upload_result');
        $this->assertCount(4, $result['created']);
        $this->assertSame([6, 7, 8], array_column($result['failed'], 'row'));
        $this->assertSame(4, Employee::query()->count());
    }

    public function test_non_excel_files_are_rejected(): void
    {
        $this->actingAsRole(User::TYPE_HR)
            ->post(route('employees.bulk-upload'), ['employees_file' => UploadedFile::fake()->create('employees.pdf', 10, 'application/pdf')])
            ->assertSessionHas('error', 'Only .xlsx or .xls files are accepted.');

        $this->assertSame(0, Employee::query()->count());
    }

    public function test_admin_can_clear_upload_history_without_touching_employees(): void
    {
        $this->actingAsRole(User::TYPE_ADMIN)
            ->post(route('admin.employees.bulk-upload'), ['employees_file' => $this->excelFile($this->sampleRows())]);

        $this->actingAsRole(User::TYPE_ADMIN)
            ->post(route('admin.employees.bulk-upload.clear'))
            ->assertRedirect(route('admin.employees.index'));

        $this->assertSame(0, AdminAuditLog::query()->whereNotNull('metadata->original_filename')->where('module', 'Employees')->count());
        $this->assertSame(4, Employee::query()->count());
        $this->assertSame(4, User::query()->where('user_type', User::TYPE_EMPLOYEE)->count());
    }

    public function test_existing_login_keeps_its_password_and_gets_no_credentials_email(): void
    {
        $hr = User::factory()->create(['user_type' => User::TYPE_HR, 'email' => 'pritongtalong808@gmail.com']);
        $passwordHash = $hr->password;

        $this->actingAsRole(User::TYPE_ADMIN)
            ->post(route('admin.employees.bulk-upload'), ['employees_file' => $this->excelFile($this->sampleRows())]);

        $this->assertSame($passwordHash, $hr->fresh()->password);
        $this->assertSame(User::TYPE_HR, $hr->fresh()->user_type);
        Mail::assertSent(EmployeeCredentialsMail::class, 3);
        Mail::assertNotSent(EmployeeCredentialsMail::class, fn ($mail) => $mail->hasTo('pritongtalong808@gmail.com'));
        $this->assertDatabaseHas('employees', ['email' => 'pritongtalong808@gmail.com']);
    }

    public function test_hr_cannot_clear_upload_history(): void
    {
        $this->actingAsRole(User::TYPE_HR)
            ->post(route('admin.employees.bulk-upload.clear'))
            ->assertForbidden();
    }

    public function test_three_hundred_rows_import_in_one_upload(): void
    {
        $rows = [];
        for ($i = 1; $i <= 300; $i++) {
            $rows[] = [sprintf('26%05d', $i), "employee{$i}@example.com", "First{$i}", "Last{$i}", 'Faculty', 'Regular', 'SABM', 'Instructor', (string) (($i % 4) + 1), '2026-06-01'];
        }

        $started = microtime(true);
        $this->actingAsRole(User::TYPE_ADMIN)
            ->post(route('admin.employees.bulk-upload'), ['employees_file' => $this->excelFile($rows, 'big.xlsx')]);
        fwrite(STDERR, sprintf("\n300-row upload incl. accounts: %.1fs\n", microtime(true) - $started));

        $this->assertCount(300, session('bulk_upload_result')['created']);
        $this->assertSame(300, Employee::query()->count());
        Mail::assertSent(EmployeeCredentialsMail::class, 300);
    }
}
