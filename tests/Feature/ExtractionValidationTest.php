<?php

namespace Tests\Feature;

use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\ExtractionValidationRun;
use App\Models\User;
use App\Services\BiometricPdfParser;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ExtractionValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A NU Lipa "TIMESHEET REPORT" style PDF with 4 attendance rows.
     */
    private function biometricPdf(): UploadedFile
    {
        $lines = [
            'TIMESHEET REPORT',
            'Employee: Jei Pastrana (23-8041)',
            '08/03/2026 7:21 am 7:41 pm',
            '08/04/2026 6:58 am 7:02 pm',
            '08/05/2026 7:10 am 7:30 pm',
            'Employee: Reymark Natividad (25-08064)',
            '08/03/2026 8:00 am 5:00 pm',
            'Total: 4',
        ];
        $html = '<html><body>'.implode('', array_map(fn ($line) => '<div>'.e($line).'</div>', $lines)).'</body></html>';

        $path = tempnam(sys_get_temp_dir(), 'dtr').'.pdf';
        file_put_contents($path, Pdf::loadHTML($html)->output());

        return new UploadedFile($path, 'timesheet_august.pdf', 'application/pdf', null, true);
    }

    /**
     * Ground truth with one wrong time-out (row 3) and one row the PDF does not contain (row 6).
     */
    private function groundTruth(): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->setTitle('Ground Truth')->fromArray([
            ['Employee ID', 'Employee Name', 'Date', 'Time In', 'Time Out'],
            ['23-8041', 'Pastrana, Jei', '2026-08-03', '7:21 AM', '7:41 PM'],
            ['238041', 'Pastrana, Jei', '2026-08-04', '6:58 AM', '7:05 PM'],
            ['23-8041', 'Pastrana, Jei', '2026-08-05', '07:10', '19:30'],
            ['2508064', 'Natividad, Reymark', '2026-08-03', '8:00 AM', '5:00 PM'],
            ['2508064', 'Natividad, Reymark', '2026-08-06', '8:00 AM', '5:00 PM'],
            ['2508064', 'Natividad, Reymark', '2026-08-07', '', ''],
        ], null, 'A1', true);

        $path = tempnam(sys_get_temp_dir(), 'gt').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        return new UploadedFile($path, 'ground_truth_august.xlsx', null, null, true);
    }

    private function admin(): static
    {
        return $this->withSession(['privacy_notice_acknowledged_version' => config('privacy.notice_version')])
            ->actingAs(User::factory()->create(['user_type' => User::TYPE_ADMIN]));
    }

    public function test_parser_reads_the_generated_biometric_pdf(): void
    {
        $result = app(BiometricPdfParser::class)->parseFile($this->biometricPdf()->getRealPath());

        $this->assertSame('nu_lipa', $result['format']);
        $this->assertCount(4, $result['records']);
        $this->assertSame(1, $result['pages']);
    }

    public function test_validation_run_computes_accuracy_metrics_and_mismatches(): void
    {
        Employee::query()->create([
            'employee_id' => '23-8041',
            'first_name' => 'Jei',
            'last_name' => 'Pastrana',
            'email' => 'jei@example.com',
            'department_id' => Department::query()->create(['name' => 'SACE'])->id,
            'position' => 'Instructor',
        ]);

        $response = $this->admin()->post(route('admin.dtr.validation.store'), [
            'pdf_file' => $this->biometricPdf(),
            'ground_truth_file' => $this->groundTruth(),
        ]);

        $run = ExtractionValidationRun::query()->sole();
        $response->assertRedirect(route('admin.dtr.validation.show', $run));

        // 5 ground truth rows (the row with no punches is ignored), 4 extracted.
        $this->assertSame(5, $run->expected_rows);
        $this->assertSame(4, $run->extracted_rows);
        $this->assertSame(4, $run->matched_rows);
        $this->assertSame(4, $run->time_in_correct);
        $this->assertSame(3, $run->time_out_correct);
        $this->assertSame(3, $run->fully_correct_rows);
        $this->assertSame(3, $run->employee_matched_rows); // only Pastrana is registered
        $this->assertSame('NU Lipa Timesheet Report', $run->format);

        $this->assertSame(80.0, ExtractionValidationRun::percent($run->matched_rows, $run->expected_rows));
        $this->assertSame(100.0, ExtractionValidationRun::percent($run->matched_rows, $run->extracted_rows));
        $this->assertSame(88.89, $run->f1Score());
        $this->assertSame(0, $run->falseRows());

        $this->assertEqualsCanonicalizing(['time_out', 'missing'], array_column($run->mismatches, 'type'));
        $timeOut = collect($run->mismatches)->firstWhere('type', 'time_out');
        $this->assertSame(['2026-08-04', '7:05 PM', '7:02 PM'], [$timeOut['date'], $timeOut['expected'], $timeOut['extracted']]);

        // Validation never touches attendance.
        $this->assertSame(0, AttendanceRecord::query()->count());
    }

    public function test_pages_and_reports_render(): void
    {
        $admin = $this->admin();
        $admin->post(route('admin.dtr.validation.store'), [
            'pdf_file' => $this->biometricPdf(),
            'ground_truth_file' => $this->groundTruth(),
        ]);
        $run = ExtractionValidationRun::query()->sole();

        $admin->get(route('admin.dtr.validation.index'))->assertOk()->assertSee('timesheet_august.pdf')->assertSee('Overall results');
        $admin->get(route('admin.dtr.validation.show', $run))->assertOk()->assertSee('Wrong time out')->assertSee('Missed row');
        $admin->get(route('admin.dtr.validation.report', $run))->assertOk()->assertHeader('content-type', 'application/pdf');
        $admin->get(route('admin.dtr.validation.summary-report'))->assertOk()->assertHeader('content-type', 'application/pdf');
        $admin->get(route('admin.dtr.validation.template'))->assertOk();
    }

    public function test_bad_ground_truth_is_reported(): void
    {
        $spreadsheet = new Spreadsheet();
        $spreadsheet->getActiveSheet()->fromArray([['Something', 'Else'], ['a', 'b']]);
        $path = tempnam(sys_get_temp_dir(), 'gt').'.xlsx';
        (new Xlsx($spreadsheet))->save($path);

        $this->admin()->post(route('admin.dtr.validation.store'), [
            'pdf_file' => $this->biometricPdf(),
            'ground_truth_file' => new UploadedFile($path, 'wrong.xlsx', null, null, true),
        ])->assertSessionHas('error');

        $this->assertSame(0, ExtractionValidationRun::query()->count());
    }

    public function test_biometric_uploads_still_import_and_report_extraction_details(): void
    {
        Employee::query()->create([
            'employee_id' => '23-8041',
            'first_name' => 'Jei',
            'last_name' => 'Pastrana',
            'email' => 'jei@example.com',
            'department_id' => Department::query()->create(['name' => 'SACE'])->id,
            'position' => 'Instructor',
        ]);

        foreach ([[User::TYPE_ADMIN, 'admin.dtr.upload'], [User::TYPE_HR, 'biometrics.upload']] as [$userType, $route]) {
            AttendanceRecord::query()->delete();

            $this->withSession(['privacy_notice_acknowledged_version' => config('privacy.notice_version')])
                ->actingAs(User::factory()->create(['user_type' => $userType]))
                ->post(route($route), ['biometrics_file' => $this->biometricPdf()])
                ->assertSessionHas('import_stats', fn (array $stats) => $stats['imported'] === 3
                    && $stats['format'] === 'NU Lipa Timesheet Report'
                    && $stats['rows_extracted'] === 4
                    && $stats['pages'] === 1);

            $this->assertSame(3, AttendanceRecord::query()->count(), $route);
        }
    }

    public function test_hr_cannot_access_validation(): void
    {
        $this->withSession(['privacy_notice_acknowledged_version' => config('privacy.notice_version')])
            ->actingAs(User::factory()->create(['user_type' => User::TYPE_HR]))
            ->get(route('admin.dtr.validation.index'))
            ->assertForbidden();
    }
}
