<?php

namespace App\Services;

use App\Models\Employee;
use App\Models\LeaveRequest;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Verifies Excel imports after they run: re-reads the uploaded file cell by
 * cell and compares every saved record with it, field by field. The file is
 * the ground truth, so no manual encoding is needed.
 *
 * Cells are read here independently of the importers, so this is a separate
 * check of what was actually saved, not the importer checking itself.
 */
class ImportVerificationService
{
    private const MAX_MISMATCHES = 100;

    /**
     * @param  array<int, array{row:int, employee_id:string}>  $createdRows  rows the bulk import reports as added
     * @return array<string, mixed>
     */
    public function verifyEmployeeImport(UploadedFile $file, array $createdRows): array
    {
        [$header, $rows] = $this->readSheet($file, 'Employees');
        $col = $this->columns($header, [
            'employee_id' => ['employeeid', 'employeeno', 'employeenumber', 'idnumber', 'idno', 'id'],
            'email' => ['email', 'emailaddress'],
            'first_name' => ['firstname'],
            'last_name' => ['lastname'],
            'employment_type' => ['employeetype', 'employmenttype', 'type'],
            'employment_status' => ['employmentstatus', 'status'],
            'department' => ['department', 'school'],
            'position' => ['rank', 'position', 'office'],
            'ranking' => ['level', 'ranking', 'facultyranking'],
            'hire_date' => ['hiredate', 'datehired'],
        ]);

        $employees = Employee::query()->with('department')->get()
            ->keyBy(fn (Employee $employee) => Employee::normalizeEmployeeId($employee->employee_id));

        $result = $this->emptyResult();

        foreach ($createdRows as $created) {
            $row = $rows[$created['row'] - 2] ?? [];
            $cell = fn (string $field) => isset($col[$field]) ? $row[$col[$field]] ?? null : null;
            $label = trim($created['employee_id'].' '.$this->text($cell('first_name')).' '.$this->text($cell('last_name')));
            $result['records_checked']++;

            $employee = $employees->get(Employee::normalizeEmployeeId($this->idText($cell('employee_id'))));

            if (! $employee) {
                $this->addMismatch($result, $created['row'], $label, 'Record', 'In file', 'Not found in database');

                continue;
            }

            $result['records_found']++;
            $type = $this->text($cell('employment_type'));
            $statusCell = $this->text($cell('employment_status'));

            $checks = [
                'First name' => [$this->text($cell('first_name')), $employee->first_name, fn ($a, $b) => $a === trim((string) $b)],
                'Last name' => [$this->text($cell('last_name')), $employee->last_name, fn ($a, $b) => $a === trim((string) $b)],
                'Email' => [$this->text($cell('email')), $employee->email, fn ($a, $b) => strcasecmp($a, (string) $b) === 0],
                'Employee type' => [$type, $employee->employment_type, fn ($a, $b) => $this->same($a, $b)],
                'Employment status' => [
                    $statusCell === '' || $this->same($type, 'Part-Time Faculty') ? 'probationary' : $statusCell,
                    $employee->employment_status,
                    fn ($a, $b) => $this->same($a, $b),
                ],
                'Rank' => [$this->text($cell('position')), $employee->position, fn ($a, $b) => $this->same($a, $b) || $this->same($a, $this->withoutSuffix($b))],
                'Level' => [$this->text($cell('ranking')), $employee->ranking, fn ($a, $b) => $a === ''
                    ? blank($b)
                    : $this->same($a, $b) || (ctype_digit($a) && str_ends_with(trim((string) $b), ' '.$a))],
                'Hire date' => [$this->date($cell('hire_date')), $employee->hire_date?->toDateString(), fn ($a, $b) => $a === $b],
            ];

            // Department is only checked when the file gives one (Admin Support rows default to ASP).
            if ($this->text($cell('department')) !== '') {
                $checks['Department'] = [$this->text($cell('department')), $employee->department?->name, fn ($a, $b) => $this->same($a, $b) || $this->same($a, explode(' - ', (string) $b)[0])];
            }

            $this->compareFields($result, $created['row'], $label, $checks);
        }

        return $this->finish($result);
    }

    /**
     * @return array<string, mixed>
     */
    public function verifyLeaveImport(UploadedFile $file, LeaveMonitoringService $leaveMonitoring): array
    {
        [$header, $rows] = $this->readSheet($file);
        $col = $this->columns($header, [
            'employee_id' => ['employeeid', 'empid'],
            'leave_id' => ['leaveid', 'applicationid', 'applicationno', 'applicationnumber'],
            'employee_name' => ['employeename', 'name'],
            'application' => ['application', 'applicationtype', 'leavetype'],
            'type' => ['type', 'category'],
            'date_from' => ['datefrom', 'from', 'startdate'],
            'date_to' => ['dateto', 'to', 'enddate'],
            'total_hours' => ['totalhours', 'hours'],
            'status' => ['status'],
        ]);

        $matcher = new EmployeeRecordMatcher(Employee::query()->get());
        $result = $this->emptyResult();
        $expected = [];

        foreach ($rows as $index => $row) {
            $cell = fn (string $field) => isset($col[$field]) ? $row[$col[$field]] ?? null : null;

            if (collect($row)->filter(fn ($value) => filled($value))->isEmpty()) {
                continue;
            }

            $employee = $matcher->match(['employee_id' => $this->idText($cell('employee_id')), 'name' => $this->text($cell('employee_name'))]);
            $startDate = $this->date($cell('date_from'));

            // Rows the import skips on purpose (unregistered employee, no start date) are not expected in the database.
            if (! $employee || ! $startDate) {
                $result['not_imported']++;

                continue;
            }

            $leaveId = $this->idText($cell('leave_id'));
            $application = $this->text($cell('application')) ?: 'Leave';
            $leaveType = $leaveMonitoring->resolvePolicy($application, $this->text($cell('type')))['storage_leave_type'];
            $key = $leaveId !== '' ? $employee->id.'|id|'.$leaveId : $employee->id.'|'.$startDate.'|'.$leaveType;

            if (isset($expected[$key])) {
                $result['superseded']++; // a later row with the same Leave ID replaces this one
            }

            $hours = (float) ($cell('total_hours') ?? 0);
            $statusText = strtoupper($this->text($cell('status')));

            $expected[$key] = [
                'row' => $index + 2,
                'label' => trim($employee->employee_id.' '.$employee->full_name.' · '.$application.($leaveId !== '' ? ' #'.$leaveId : '')),
                'employee_id' => $employee->id,
                'leave_id' => $leaveId,
                'application' => $application,
                'leave_type' => $leaveType,
                'start_date' => $startDate,
                'end_date' => $this->date($cell('date_to')) ?? $startDate,
                'days' => $hours > 0 ? round($hours / 8, 2) : 0.0,
                'status' => match (true) {
                    str_contains($statusText, 'APPROVED') && ! str_contains($statusText, 'DIS') => 'approved',
                    str_contains($statusText, 'DIS') || str_contains($statusText, 'CANCEL') || str_contains($statusText, 'REJECT') => 'rejected',
                    default => 'pending',
                },
            ];
        }

        $saved = LeaveRequest::query()
            ->whereIn('employee_id', collect($expected)->pluck('employee_id')->unique())
            ->get();
        $bySourceId = $saved->filter(fn ($leave) => filled($leave->source_leave_id))
            ->keyBy(fn ($leave) => $leave->employee_id.'|id|'.$leave->source_leave_id);

        foreach ($expected as $key => $item) {
            $result['records_checked']++;

            $leave = $item['leave_id'] !== ''
                ? $bySourceId->get($key)
                : $saved->first(fn ($leave) => $leave->employee_id === $item['employee_id']
                    && $leave->start_date?->toDateString() === $item['start_date']
                    && in_array($leave->leave_type, [$item['application'], $item['leave_type']], true));

            if (! $leave) {
                $this->addMismatch($result, $item['row'], $item['label'], 'Record', 'In file', 'Not found in database');

                continue;
            }

            $result['records_found']++;

            $this->compareFields($result, $item['row'], $item['label'], [
                'Leave type' => [$item['leave_type'], $leave->leave_type, fn ($a, $b) => $a === $b],
                'Date from' => [$item['start_date'], $leave->start_date?->toDateString(), fn ($a, $b) => $a === $b],
                'Date to' => [$item['end_date'], $leave->end_date?->toDateString(), fn ($a, $b) => $a === $b],
                'Days (hours ÷ 8)' => [$item['days'], (float) $leave->days_deducted, fn ($a, $b) => abs($a - $b) < 0.01],
                'Status' => [$item['status'], $leave->status, fn ($a, $b) => $a === $b],
            ]);
        }

        return $this->finish($result);
    }

    public static function percent(int $correct, int $total): ?float
    {
        return $total > 0 ? round($correct / $total * 100, 2) : null;
    }

    /**
     * @param  array<string, array{0:mixed, 1:mixed, 2:callable}>  $checks  label => [file value, saved value, comparator]
     */
    private function compareFields(array &$result, int $row, string $label, array $checks): void
    {
        $allCorrect = true;

        foreach ($checks as $field => [$fileValue, $savedValue, $matches]) {
            $result['fields_checked']++;

            if ($matches($fileValue, $savedValue)) {
                $result['fields_correct']++;

                continue;
            }

            $allCorrect = false;
            $this->addMismatch($result, $row, $label, $field, $this->display($fileValue), $this->display($savedValue));
        }

        $result['fully_correct'] += (int) $allCorrect;
    }

    private function addMismatch(array &$result, ?int $row, string $label, string $field, string $file, string $saved): void
    {
        $result['mismatch_count']++;

        if (count($result['mismatches']) < self::MAX_MISMATCHES) {
            $result['mismatches'][] = compact('row', 'label', 'field', 'file', 'saved');
        }
    }

    private function emptyResult(): array
    {
        return [
            'records_checked' => 0,
            'records_found' => 0,
            'fields_checked' => 0,
            'fields_correct' => 0,
            'fully_correct' => 0,
            'not_imported' => 0,
            'superseded' => 0,
            'mismatch_count' => 0,
            'mismatches' => [],
        ];
    }

    private function finish(array $result): array
    {
        return [
            ...$result,
            'field_accuracy' => self::percent($result['fields_correct'], $result['fields_checked']),
            'record_accuracy' => self::percent($result['fully_correct'], $result['records_checked']),
        ];
    }

    /**
     * @return array{0: array<int, mixed>, 1: array<int, array<int, mixed>>}
     */
    private function readSheet(UploadedFile $file, ?string $preferredSheet = null): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getRealPath());
        $sheet = ($preferredSheet ? $spreadsheet->getSheetByName($preferredSheet) : null) ?? $spreadsheet->getActiveSheet();
        $rows = $sheet->toArray(null, true, false, false);

        return [array_shift($rows) ?? [], $rows];
    }

    /**
     * @param  array<int, mixed>  $header
     * @param  array<string, array<int, string>>  $aliases
     * @return array<string, int>
     */
    private function columns(array $header, array $aliases): array
    {
        $columns = [];

        foreach ($header as $index => $label) {
            $key = $this->key($label);

            foreach ($aliases as $field => $names) {
                if (! isset($columns[$field]) && in_array($key, $names, true)) {
                    $columns[$field] = $index;
                }
            }
        }

        return $columns;
    }

    private function text(mixed $value): string
    {
        return trim((string) ($value ?? ''));
    }

    private function idText(mixed $value): string
    {
        return is_float($value) || is_int($value) ? (string) (int) $value : $this->text($value);
    }

    private function key(mixed $value): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');
    }

    private function same(mixed $a, mixed $b): bool
    {
        return $this->key($a) !== '' && $this->key($a) === $this->key($b);
    }

    private function withoutSuffix(mixed $value): string
    {
        return trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', (string) $value));
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

    private function display(mixed $value): string
    {
        return match (true) {
            $value === null || $value === '' => '(blank)',
            is_float($value) => rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.'),
            default => (string) $value,
        };
    }
}
