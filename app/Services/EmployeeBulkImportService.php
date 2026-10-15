<?php

namespace App\Services;

use App\Http\Requests\StoreEmployeeRequest;
use App\Models\Department;
use App\Models\Employee;
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Exists;
use Illuminate\Validation\Rules\Unique;
use Illuminate\Validation\ValidationException;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;

/**
 * Imports employees from an Excel sheet (one employee per row). Each row is
 * validated with the same rules as the Add Employee form; rows whose
 * Employee ID is already registered (with or without dash) are skipped.
 */
class EmployeeBulkImportService
{
    /**
     * Normalized header text => employee attribute.
     */
    private const HEADER_ALIASES = [
        'employeeid' => 'employee_id',
        'employeeno' => 'employee_id',
        'employeenumber' => 'employee_id',
        'idnumber' => 'employee_id',
        'idno' => 'employee_id',
        'id' => 'employee_id',
        'email' => 'email',
        'emailaddress' => 'email',
        'firstname' => 'first_name',
        'lastname' => 'last_name',
        'employeetype' => 'employment_type',
        'employmenttype' => 'employment_type',
        'type' => 'employment_type',
        'employmentstatus' => 'employment_status',
        'status' => 'employment_status',
        'department' => 'department',
        'school' => 'department',
        'rank' => 'position',
        'position' => 'position',
        'office' => 'position',
        'level' => 'ranking',
        'ranking' => 'ranking',
        'facultyranking' => 'ranking',
        'hiredate' => 'hire_date',
        'datehired' => 'hire_date',
    ];

    private const REQUIRED_COLUMNS = ['employee_id', 'email', 'first_name', 'last_name', 'employment_type', 'position'];

    /** @var array<string, int>|null comparable department name or code => id */
    private ?array $departmentLookup = null;

    /**
     * @return array{
     *     total_rows:int,
     *     created:array<int, array{row:int, employee_id:string, name:string}>,
     *     existing:array<int, array{row:int, employee_id:string, name:string}>,
     *     failed:array<int, array{row:int, employee_id:string, name:string, reason:string}>,
     *     created_employee_ids:array<int, int>
     * }
     */
    public function import(UploadedFile $file): array
    {
        $rows = $this->readRows($file);
        $header = array_shift($rows) ?? [];
        $columns = $this->mapColumns($header);

        $missing = array_diff(self::REQUIRED_COLUMNS, array_keys($columns));
        if ($missing !== []) {
            throw new \InvalidArgumentException('The file is missing these columns: '.implode(', ', array_map(
                fn ($column) => Str::headline(str_replace(['position', 'ranking'], ['rank', 'level'], $column)),
                $missing
            )).'.');
        }

        // Load lookups once: every query is a round-trip to the remote database,
        // so per-row queries make a 300-row file take minutes.
        $existingIds = Employee::withTrashed()
            ->pluck('employee_id')
            ->mapWithKeys(fn ($id) => [Employee::normalizeEmployeeId($id) => true])
            ->all();
        $existingEmails = Employee::withTrashed()
            ->pluck('email')
            ->mapWithKeys(fn ($email) => [strtolower((string) $email) => true])
            ->all();
        $this->departmentId(null);

        $result = ['total_rows' => 0, 'created' => [], 'existing' => [], 'failed' => [], 'created_employee_ids' => []];
        $pending = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2; // header is row 1
            $data = $this->rowData($row, $columns);

            if (collect($data)->filter(fn ($value) => filled($value))->isEmpty()) {
                continue;
            }

            $result['total_rows']++;
            $employeeId = (string) ($data['employee_id'] ?? '');
            $name = trim(($data['first_name'] ?? '').' '.($data['last_name'] ?? ''));
            $normalizedId = Employee::normalizeEmployeeId($employeeId);
            $fail = function (string $reason) use (&$result, $rowNumber, $employeeId, $name): void {
                $result['failed'][] = ['row' => $rowNumber, 'employee_id' => $employeeId, 'name' => $name, 'reason' => $reason];
            };

            if ($normalizedId === '') {
                $fail('Employee ID is required.');

                continue;
            }

            if (isset($existingIds[$normalizedId])) {
                $result['existing'][] = ['row' => $rowNumber, 'employee_id' => $employeeId, 'name' => $name];

                continue;
            }

            $input = $this->toFormInput($data);

            if (isset($existingEmails[$input['email']])) {
                $fail('The email '.$input['email'].' is already used by another employee.');

                continue;
            }

            try {
                $payload = $this->validate($input);
            } catch (ValidationException $exception) {
                $fail(implode(' ', Arr::flatten($exception->errors())));

                continue;
            }

            // Reserve the ID and email so a repeated row in the same file is caught too.
            $existingIds[$normalizedId] = true;
            $existingEmails[$input['email']] = true;
            $pending[] = ['row' => $rowNumber, 'attributes' => $this->withDefaultDepartment($payload)];
        }

        foreach (array_chunk($pending, 100) as $chunk) {
            $now = now();
            $records = array_map(fn (array $item) => $this->insertableRecord($item['attributes'], $now), $chunk);

            try {
                Employee::query()->insert($records);
            } catch (\Throwable $exception) {
                Log::warning('Bulk employee import chunk failed', ['error' => $exception->getMessage()]);

                foreach ($chunk as $item) {
                    $result['failed'][] = [
                        'row' => $item['row'],
                        'employee_id' => (string) $item['attributes']['employee_id'],
                        'name' => trim($item['attributes']['first_name'].' '.$item['attributes']['last_name']),
                        'reason' => 'Could not save this employee.',
                    ];
                }

                continue;
            }

            $savedIds = Employee::query()
                ->whereIn('employee_id', array_column($records, 'employee_id'))
                ->pluck('id', 'employee_id');

            foreach ($chunk as $item) {
                $attributes = $item['attributes'];
                $result['created'][] = [
                    'row' => $item['row'],
                    'employee_id' => (string) $attributes['employee_id'],
                    'name' => trim($attributes['first_name'].' '.$attributes['last_name']),
                ];
                $result['created_employee_ids'][] = (int) $savedIds[$attributes['employee_id']];
            }
        }

        usort($result['failed'], fn (array $a, array $b) => $a['row'] <=> $b['row']);

        return $result;
    }

    /**
     * @return array<int, array<int, mixed>>
     */
    private function readRows(UploadedFile $file): array
    {
        $reader = IOFactory::createReaderForFile($file->getRealPath());
        $reader->setReadDataOnly(true);
        $spreadsheet = $reader->load($file->getRealPath());

        // Prefer the "Employees" sheet so a Guide sheet in the template is ignored.
        $sheet = $spreadsheet->getSheetByName('Employees') ?? $spreadsheet->getSheet(0);

        return $sheet->toArray(null, true, false, false);
    }

    /**
     * @param  array<int, mixed>  $header
     * @return array<string, int> attribute => column index
     */
    private function mapColumns(array $header): array
    {
        $columns = [];

        foreach ($header as $index => $label) {
            $key = strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $label) ?? '');
            $attribute = self::HEADER_ALIASES[$key] ?? null;

            if ($attribute && ! isset($columns[$attribute])) {
                $columns[$attribute] = $index;
            }
        }

        return $columns;
    }

    /**
     * @param  array<int, mixed>  $row
     * @param  array<string, int>  $columns
     * @return array<string, mixed>
     */
    private function rowData(array $row, array $columns): array
    {
        $data = [];

        foreach ($columns as $attribute => $index) {
            $value = $row[$index] ?? null;
            $data[$attribute] = is_string($value) ? trim($value) : $value;
        }

        if (is_float($data['employee_id'] ?? null) || is_int($data['employee_id'] ?? null)) {
            $data['employee_id'] = (string) (int) $data['employee_id'];
        }

        return $data;
    }

    /**
     * Translate spreadsheet values into the same input the Add Employee form posts.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function toFormInput(array $data): array
    {
        $employmentType = $this->matchOption($data['employment_type'] ?? null, (array) config('hris.employment_types', []));
        $position = $this->matchPosition($data['position'] ?? null);

        return [
            'employee_id' => (string) ($data['employee_id'] ?? ''),
            'email' => strtolower((string) ($data['email'] ?? '')),
            'first_name' => (string) ($data['first_name'] ?? ''),
            'last_name' => (string) ($data['last_name'] ?? ''),
            'employment_type' => $employmentType ?? (string) ($data['employment_type'] ?? ''),
            'employment_status' => filled($data['employment_status'] ?? null) && ! $this->isPartTime($employmentType)
                ? strtolower((string) $data['employment_status'])
                : 'probationary',
            'department_id' => $position && Str::contains(Str::lower($position), '(shs)')
                ? $this->departmentId('SHS')
                : $this->departmentId($data['department'] ?? null),
            'position' => $position ?? (string) ($data['position'] ?? ''),
            'ranking' => $this->ranking($data['ranking'] ?? null, $position),
            'hire_date' => $this->date($data['hire_date'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array<string, mixed>
     */
    private function validate(array $input): array
    {
        // Same rules as the Add Employee form. Database-backed rules (unique /
        // exists) are dropped because uniqueness and departments are checked
        // against lookups loaded once per file.
        $request = StoreEmployeeRequest::create('/', 'POST', $input);
        $rules = array_map(fn ($fieldRules) => array_values(array_filter(
            (array) $fieldRules,
            fn ($rule) => ! (is_string($rule) && (str_starts_with($rule, 'unique:') || str_starts_with($rule, 'exists:')))
                && ! $rule instanceof Unique
                && ! $rule instanceof Exists,
        )), $request->rules());

        return Validator::make($input, $rules, $request->messages(), $request->attributes())->validate();
    }

    /**
     * Department defaults from Employee::withDefaultDepartment, using the
     * cached department lookup instead of a query per row.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function withDefaultDepartment(array $payload): array
    {
        $position = Str::lower((string) ($payload['position'] ?? ''));
        $isFaculty = Str::contains(Str::lower((string) ($payload['employment_type'] ?? '')), 'faculty')
            || in_array($position, array_map('strtolower', (array) config('hris.faculty_positions', [])), true);

        if (Str::contains($position, '(shs)')) {
            $payload['department_id'] = $this->departmentId('SHS') ?? $payload['department_id'] ?? null;
        } elseif (! $isFaculty) {
            $payload['department_id'] = $this->departmentId('ASP') ?? $payload['department_id'] ?? null;
        }

        return $payload;
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    private function insertableRecord(array $attributes, Carbon $now): array
    {
        $columns = ['employee_id', 'first_name', 'last_name', 'email', 'department_id', 'position', 'employment_type', 'employment_status', 'ranking', 'hire_date'];
        $record = [];

        foreach ($columns as $column) {
            $record[$column] = $attributes[$column] ?? null;
        }

        return [...$record, 'status' => 'active', 'created_at' => $now, 'updated_at' => $now];
    }

    /**
     * @param  array<int, string>  $options
     */
    private function matchOption(mixed $value, array $options): ?string
    {
        $needle = $this->comparable($value);

        if ($needle === '') {
            return null;
        }

        foreach ($options as $option) {
            if ($this->comparable($option) === $needle) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Match a rank/office, tolerating a missing abbreviation suffix such as
     * "Human Resource Office" for "Human Resource Office (HR)".
     */
    private function matchPosition(mixed $value): ?string
    {
        $options = array_values(array_unique(array_merge(
            (array) config('hris.faculty_positions', []),
            (array) config('hris.admin_support_offices', []),
        )));

        if ($exact = $this->matchOption($value, $options)) {
            return $exact;
        }

        $needle = $this->comparable($value);

        foreach ($options as $option) {
            $withoutSuffix = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $option));

            if ($needle !== '' && $this->comparable($withoutSuffix) === $needle) {
                return $option;
            }
        }

        return null;
    }

    /**
     * Accepts a department code ("SACE") or full name.
     */
    private function departmentId(mixed $value): ?int
    {
        if ($this->departmentLookup === null) {
            Department::ensureConfiguredSchoolsExist();
            $this->departmentLookup = [];

            foreach (Department::query()->get(['id', 'name']) as $department) {
                $this->departmentLookup[$this->comparable(Str::before($department->name, ' - '))] ??= $department->id;
                $this->departmentLookup[$this->comparable($department->name)] = $department->id;
            }
        }

        $needle = $this->comparable($value);

        return $needle === '' ? null : ($this->departmentLookup[$needle] ?? null);
    }

    /**
     * A level of "3" with rank "Assistant Professor" becomes "Assistant Professor 3".
     */
    private function ranking(mixed $level, ?string $position): ?string
    {
        if (blank($level)) {
            return null;
        }

        $rankings = (array) config('hris.faculty_rankings', []);
        $level = is_float($level) ? (string) (int) $level : trim((string) $level);

        if ($exact = $this->matchOption($level, $rankings)) {
            return $exact;
        }

        if ($position && ctype_digit($level)) {
            $base = trim((string) preg_replace('/\s*\([^)]*\)\s*$/', '', $position));

            return $this->matchOption($base.' '.$level, $rankings) ?? $base.' '.$level;
        }

        return $level;
    }

    private function date(mixed $value): ?string
    {
        if (blank($value)) {
            return null;
        }

        try {
            if (is_numeric($value)) {
                return Carbon::instance(ExcelDate::excelToDateTimeObject((float) $value))->toDateString();
            }

            return Carbon::parse((string) $value)->toDateString();
        } catch (\Throwable) {
            return (string) $value;
        }
    }

    private function isPartTime(?string $employmentType): bool
    {
        return Str::lower((string) $employmentType) === 'part-time faculty';
    }

    private function comparable(mixed $value): string
    {
        return strtolower(preg_replace('/[^A-Za-z0-9]/', '', (string) $value) ?? '');
    }
}
