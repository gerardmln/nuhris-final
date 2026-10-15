<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\ExtractionValidationRun;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Spreadsheet;

/**
 * Measures biometric PDF extraction accuracy by comparing what the parser
 * extracts against a ground truth file encoded by hand from the same PDF.
 * Nothing is written to attendance records.
 */
class ExtractionValidationService
{
    public const TEMPLATE_HEADERS = ['Employee ID', 'Employee Name', 'Date', 'Time In', 'Time Out'];

    private const MAX_STORED_MISMATCHES = 500;

    private const HEADER_ALIASES = [
        'employeeid' => 'employee_id',
        'idnumber' => 'employee_id',
        'id' => 'employee_id',
        'employeename' => 'name',
        'name' => 'name',
        'date' => 'date',
        'timein' => 'time_in',
        'in' => 'time_in',
        'timeout' => 'time_out',
        'out' => 'time_out',
    ];

    public function __construct(private BiometricPdfParser $parser)
    {
    }

    public function run(UploadedFile $pdf, UploadedFile $groundTruthFile, ?int $userId = null): ExtractionValidationRun
    {
        $extraction = $this->parser->parseFile($pdf->getRealPath());
        $groundTruth = $this->readGroundTruth($groundTruthFile);

        $comparison = $this->compare($groundTruth, $extraction['records']);

        return ExtractionValidationRun::query()->create([
            'user_id' => $userId,
            'pdf_filename' => $pdf->getClientOriginalName(),
            'ground_truth_filename' => $groundTruthFile->getClientOriginalName(),
            'format' => $extraction['format_label'],
            'pages' => $extraction['pages'],
            'extraction_seconds' => $extraction['seconds'],
            'expected_rows' => count($groundTruth),
            'extracted_rows' => count($extraction['records']),
            'employee_matched_rows' => $this->countRegisteredEmployees($extraction['records']),
            ...$comparison,
        ]);
    }

    /**
     * @param  array<int, array{row:int, employee_id:string, name:string, date:string, time_in:?string, time_out:?string}>  $expected
     * @param  array<int, array{employee_id:string, name:string, date:string, time_in:?string, time_out:?string}>  $extracted
     * @return array{matched_rows:int, time_in_correct:int, time_out_correct:int, fully_correct_rows:int, mismatches:array<int, array<string, mixed>>}
     */
    public function compare(array $expected, array $extracted): array
    {
        $byDate = [];
        foreach ($extracted as $index => $record) {
            $byDate[$record['date']][] = $index;
        }

        $used = [];
        $matched = 0;
        $timeInCorrect = 0;
        $timeOutCorrect = 0;
        $fullyCorrect = 0;
        $mismatches = [];

        foreach ($expected as $row) {
            $matchIndex = null;

            foreach ($byDate[$row['date']] ?? [] as $index) {
                if (! isset($used[$index]) && $this->sameEmployee($row, $extracted[$index])) {
                    $matchIndex = $index;
                    break;
                }
            }

            if ($matchIndex === null) {
                $mismatches[] = $this->mismatch('missing', $row, $row['date'], 'Row', 'Present in PDF', 'Not extracted');

                continue;
            }

            $used[$matchIndex] = true;
            $matched++;
            $record = $extracted[$matchIndex];

            $timeInOk = $this->time($row['time_in']) === $this->time($record['time_in']);
            $timeOutOk = $this->time($row['time_out']) === $this->time($record['time_out']);

            $timeInCorrect += (int) $timeInOk;
            $timeOutCorrect += (int) $timeOutOk;
            $fullyCorrect += (int) ($timeInOk && $timeOutOk);

            if (! $timeInOk) {
                $mismatches[] = $this->mismatch('time_in', $row, $row['date'], 'Time in', $this->display($row['time_in']), $this->display($record['time_in']));
            }

            if (! $timeOutOk) {
                $mismatches[] = $this->mismatch('time_out', $row, $row['date'], 'Time out', $this->display($row['time_out']), $this->display($record['time_out']));
            }
        }

        foreach ($extracted as $index => $record) {
            if (! isset($used[$index])) {
                $mismatches[] = $this->mismatch('extra', ['row' => null, ...$record], $record['date'], 'Row', 'Not in PDF', trim($this->display($record['time_in']).' - '.$this->display($record['time_out'])));
            }
        }

        return [
            'matched_rows' => $matched,
            'time_in_correct' => $timeInCorrect,
            'time_out_correct' => $timeOutCorrect,
            'fully_correct_rows' => $fullyCorrect,
            'mismatches' => array_slice($mismatches, 0, self::MAX_STORED_MISMATCHES),
        ];
    }

    /**
     * Ground truth: one row per day that has at least one punch.
     *
     * @return array<int, array{row:int, employee_id:string, name:string, date:string, time_in:?string, time_out:?string}>
     */
    public function readGroundTruth(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getRealPath());
        $sheet = $spreadsheet->getSheetByName('Ground Truth') ?? $spreadsheet->getSheet(0);
        $rows = $sheet->toArray(null, true, false, false);

        $header = array_shift($rows) ?? [];
        $columns = [];
        foreach ($header as $index => $label) {
            $key = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $label) ?? '');
            if (isset(self::HEADER_ALIASES[$key]) && ! isset($columns[self::HEADER_ALIASES[$key]])) {
                $columns[self::HEADER_ALIASES[$key]] = $index;
            }
        }

        if (! isset($columns['date']) || (! isset($columns['employee_id']) && ! isset($columns['name']))) {
            throw new \InvalidArgumentException('The ground truth file needs a Date column and an Employee ID or Employee Name column. Download the template to see the expected format.');
        }

        $groundTruth = [];

        foreach ($rows as $index => $row) {
            $value = fn (string $column) => isset($columns[$column]) ? ($row[$columns[$column]] ?? null) : null;
            $date = $this->date($value('date'));
            $timeIn = $this->time($value('time_in'));
            $timeOut = $this->time($value('time_out'));

            if (! $date || (! $timeIn && ! $timeOut)) {
                continue;
            }

            $employeeId = $value('employee_id');
            $groundTruth[] = [
                'row' => $index + 2,
                'employee_id' => is_float($employeeId) || is_int($employeeId) ? (string) (int) $employeeId : trim((string) $employeeId),
                'name' => trim((string) $value('name')),
                'date' => $date,
                'time_in' => $timeIn,
                'time_out' => $timeOut,
            ];
        }

        if ($groundTruth === []) {
            throw new \InvalidArgumentException('The ground truth file has no rows with a date and time. Fill in one row per day that has at least one punch.');
        }

        return $groundTruth;
    }

    public function template(): Spreadsheet
    {
        $spreadsheet = new Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet()->setTitle('Ground Truth');
        $sheet->fromArray([
            self::TEMPLATE_HEADERS,
            ['23-8041', 'Pastrana, Jei', '2026-08-03', '7:21 AM', '7:41 PM'],
            ['23-8041', 'Pastrana, Jei', '2026-08-04', '6:58 AM', ''],
        ]);
        $sheet->getStyle('A1:E1')->getFont()->setBold(true);
        $sheet->getStyle('A:E')->getNumberFormat()->setFormatCode('@');
        foreach (range('A', 'E') as $column) {
            $sheet->getColumnDimension($column)->setAutoSize(true);
        }

        $guide = $spreadsheet->createSheet()->setTitle('Guide');
        $guide->fromArray(array_map(fn ($line) => [$line], [
            'How to prepare the ground truth',
            '1. Open the biometric PDF you will validate.',
            '2. Encode BY HAND one row per day that has at least one punch. Do not copy from the system.',
            '3. Employee ID: as printed in the PDF. Leave blank if the PDF has no IDs (Civil Service Form 48) and fill Employee Name instead.',
            '4. Employee Name: as printed, e.g. "Pastrana, Jei".',
            '5. Date: YYYY-MM-DD.  Time In / Time Out: first and last punch of the day, e.g. 7:21 AM. Leave Time Out blank if there is only one punch.',
            '6. Delete the two example rows before uploading.',
        ]));
        $guide->getColumnDimension('A')->setWidth(110);
        $guide->getStyle('A1')->getFont()->setBold(true);
        $spreadsheet->setActiveSheetIndex(0);

        return $spreadsheet;
    }

    /**
     * @param  array<int, array{employee_id:string, name:string}>  $records
     */
    private function countRegisteredEmployees(array $records): int
    {
        $matcher = new EmployeeRecordMatcher(Employee::query()->get());

        return collect($records)->filter(fn (array $record) => $matcher->match($record) !== null)->count();
    }

    private function sameEmployee(array $expected, array $extracted): bool
    {
        $expectedId = Employee::normalizeEmployeeId($expected['employee_id'] ?? '');
        $extractedId = Employee::normalizeEmployeeId($extracted['employee_id'] ?? '');

        if ($expectedId !== '' && $extractedId !== '') {
            return $expectedId === $extractedId;
        }

        $expectedName = $this->nameKey($expected['name'] ?? '');

        return $expectedName !== '' && $expectedName === $this->nameKey($extracted['name'] ?? '');
    }

    /**
     * "Pastrana, Jei C" and "Jei Pastrana" both become "pastrana|jei".
     */
    private function nameKey(string $name): string
    {
        $name = mb_strtolower(trim($name));

        if ($name === '') {
            return '';
        }

        if (str_contains($name, ',')) {
            [$last, $first] = array_map('trim', explode(',', $name, 2));
        } else {
            $parts = preg_split('/\s+/', $name);
            $last = (string) array_pop($parts);
            $first = implode(' ', $parts);
        }

        $firstWord = explode(' ', $first)[0] ?? '';

        return preg_replace('/[^a-z]/', '', $last).'|'.preg_replace('/[^a-z]/', '', $firstWord);
    }

    private function mismatch(string $type, array $row, string $date, string $field, string $expected, string $extracted): array
    {
        $employee = trim(($row['employee_id'] ?? '').' '.($row['name'] ?? ''));

        return [
            'type' => $type,
            'row' => $row['row'] ?? null,
            'employee' => $employee !== '' ? $employee : '—',
            'date' => $date,
            'field' => $field,
            'expected' => $expected,
            'extracted' => $extracted,
        ];
    }

    private function date(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            return is_numeric($value)
                ? Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString()
                : Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return null;
        }
    }

    /**
     * Any time representation ("7:21 am", "07:21", "19:41:00", Excel fraction) => "HH:MM".
     */
    public function time(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        if (is_numeric($value)) {
            $minutes = (int) round(fmod((float) $value, 1) * 1440) % 1440;

            return sprintf('%02d:%02d', intdiv($minutes, 60), $minutes % 60);
        }

        $value = trim((string) $value);

        if (preg_match('/^(\d{1,2}):(\d{2})(?::\d{2})?\s*(am|pm)?$/i', $value, $matches)) {
            $hour = (int) $matches[1];
            $period = strtolower($matches[3] ?? '');

            if ($period === 'pm' && $hour !== 12) {
                $hour += 12;
            } elseif ($period === 'am' && $hour === 12) {
                $hour = 0;
            }

            return sprintf('%02d:%s', $hour, $matches[2]);
        }

        return null;
    }

    private function display(?string $time): string
    {
        $normalized = $this->time($time);

        return $normalized ? Carbon::createFromFormat('H:i', $normalized)->format('g:i A') : '(none)';
    }
}
