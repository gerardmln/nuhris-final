<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Http\Controllers\Hr\OperationsController as HrOperationsController;
use App\Models\AdminAuditLog;
use App\Models\Announcement;
use App\Models\AnnouncementNotification;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Employee;
use App\Models\EmployeeCredential;
use App\Models\EmployeeScheduleSubmission;
use App\Models\LeaveBalance;
use App\Models\LeaveRequest;
use App\Models\User;
use App\Models\WfhMonitoringSubmission;
use App\Services\AuditLogService;
use App\Services\BiometricPdfParser;
use App\Services\EmployeeRecordMatcher;
use App\Services\EmployeeScheduleService;
use App\Services\LeaveBalanceService;
use App\Services\LeaveMonitoringService;
use App\Services\SupabaseStorageService;
use Carbon\Carbon;
use Carbon\CarbonPeriod;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;

class OperationsController extends Controller
{
    protected SupabaseStorageService $storage;
    public function __construct(SupabaseStorageService $storage)
    {
        $this->storage = $storage;
    }

    /**
     * ========== CREDENTIAL MANAGEMENT (ADMIN-ONLY) ==========
     */

    public function credentials(Request $request): View
    {
        $statusFilter = $request->string('status')->toString() ?: 'pending';
        $search = $request->string('search')->toString();
        $credentialType = $request->string('credential_type')->toString() ?: 'all';
        $departmentId = $request->string('department_id')->toString() ?: 'all';
        $expirationStatus = $request->string('expiration_status')->toString() ?: 'all';

        $query = EmployeeCredential::query()
            ->with(['employee.department', 'reviewer'])
            ->latest('updated_at');

        if ($search !== '') {
            $query->where(function ($nested) use ($search): void {
                $nested->where('title', 'like', '%'.$search.'%')
                    ->orWhere('description', 'like', '%'.$search.'%')
                    ->orWhereHas('employee', function ($employeeQuery) use ($search): void {
                        $employeeQuery->where('first_name', 'like', '%'.$search.'%')
                            ->orWhere('last_name', 'like', '%'.$search.'%')
                            ->orWhere('email', 'like', '%'.$search.'%')
                            ->orWhere('employee_id', 'like', '%'.$search.'%');
                    });
            });
        }

        if ($credentialType !== 'all') {
            $query->where('credential_type', $credentialType);
        }

        if ($departmentId !== 'all') {
            if ($departmentId === 'asp') {
                $query->whereHas('employee', function ($employeeQuery): void {
                    $employeeQuery->where('employment_type', 'Admin Support Personnel')
                        ->orWhereHas('department', fn ($departmentQuery) => $departmentQuery->where('name', 'ASP'));
                });
            } else {
                $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('department_id', $departmentId));
            }
        }

        if ($statusFilter === 'expiring') {
            $query->where('status', 'verified');
        } elseif (in_array($statusFilter, ['pending', 'verified', 'rejected'], true)) {
            $query->where('status', $statusFilter);
        }

        $credentials = $query->get()->map(function (EmployeeCredential $credential) {
            return [
                'id' => $credential->id,
                'employee_id' => $credential->employee_id,
                'employee_name' => $credential->employee?->full_name ?? '—',
                'employee_email' => $credential->employee?->email ?? '',
                'department' => $credential->employee?->department?->name ?? 'Unassigned',
                'type_label' => $credential->typeLabel(),
                'title' => $credential->title ?: $credential->typeLabel(),
                'description' => $credential->description,
                'expires_at' => $credential->effectiveExpiresAt()?->format('M d, Y'),
                'submitted_at' => $credential->created_at?->format('M d, Y h:i A'),
                'status' => $credential->status,
                'is_expired' => $credential->isExpired(),
                'is_expiring_soon' => $credential->status === 'verified' && $credential->isExpiringSoon(),
                'has_file' => !empty($credential->file_path),
                'original_filename' => $credential->original_filename,
                'review_notes' => $credential->review_notes,
                'reviewer_name' => $credential->reviewer?->name,
                'reviewed_at' => $credential->reviewed_at?->format('M d, Y h:i A'),
            ];
        });

        if ($statusFilter === 'expiring') {
            $credentials = $credentials
                ->filter(fn (array $credential) => !empty($credential['is_expiring_soon']))
                ->values();
        }

        if ($expirationStatus === 'expiring') {
            $credentials = $credentials->filter(fn (array $credential) => ! empty($credential['is_expiring_soon']))->values();
        } elseif ($expirationStatus === 'expired') {
            $credentials = $credentials->filter(fn (array $credential) => ! empty($credential['is_expired']))->values();
        } elseif ($expirationStatus === 'valid') {
            $credentials = $credentials->filter(fn (array $credential) => ! $credential['is_expired'] && ! $credential['is_expiring_soon'])->values();
        }

        $counts = [
            'pending' => EmployeeCredential::query()->where('status', 'pending')->count(),
            'verified' => EmployeeCredential::query()->where('status', 'verified')->count(),
            'rejected' => EmployeeCredential::query()->where('status', 'rejected')->count(),
            'total' => EmployeeCredential::query()->count(),
        ];

        return view('admin.credentials.index', [
            'credentials' => $credentials,
            'counts' => $counts,
            'statusFilter' => $statusFilter,
            'departments' => Department::query()->facultySchools()->orderBy('name')->get(),
            'filters' => [
                'search' => $search,
                'credential_type' => $credentialType,
                'department_id' => $departmentId,
                'expiration_status' => $expirationStatus,
            ],
        ]);
    }

    public function editCredential(EmployeeCredential $credential): View
    {
        return view('admin.credentials.edit', [
            'credential' => $credential,
        ]);
    }

    public function viewCredentialFile(EmployeeCredential $credential): View|RedirectResponse
    {
        $employeeName = $credential->employee?->full_name ?? 'Unknown Employee';

        if (! $credential->file_path) {
            $this->logAudit('VIEW', 'Credentials', 'Failed to view credential file for '.$employeeName.'. No file was attached.', 'Failed', [
                'credential_id' => $credential->id,
            ]);

            return back()->with('error', 'No file was attached to this credential.');
        }

        if (! $this->storage->isEnabled()) {
            $this->logAudit('VIEW', 'Credentials', 'Failed to view credential file for '.$employeeName.'. Storage is not configured.', 'Failed', [
                'credential_id' => $credential->id,
            ]);

            return back()->with('error', 'File storage is not configured. Please contact the administrator.');
        }

        $url = $this->storage->createSignedUrl($credential->file_path, 300);

        if (! $url) {
            $this->logAudit('VIEW', 'Credentials', 'Failed to view credential file for '.$employeeName.'.', 'Failed', [
                'credential_id' => $credential->id,
            ]);

            return back()->with('error', 'Unable to generate a download link. Please try again.');
        }

        if (strtolower(pathinfo($credential->original_filename ?: $credential->file_path, PATHINFO_EXTENSION)) === 'pdf') {
            return redirect()->away($url);
        }

        $this->logAudit('VIEW', 'Credentials', 'Viewed credential file for '.$employeeName.'.', 'Success', [
            'credential_id' => $credential->id,
            'title' => $credential->title,
        ]);

        return view('files.preview', [
            'url' => $url,
            'filename' => $credential->original_filename ?: basename($credential->file_path),
        ]);
    }

    public function updateCredential(Request $request, EmployeeCredential $credential): RedirectResponse
    {
        $validated = $request->validate([
            'title' => 'nullable|string|max:255',
            'description' => 'nullable|string',
            'expires_at' => 'nullable|date',
            'review_notes' => 'nullable|string',
        ]);

        $credential->update($validated);

        $this->logAudit(
            'UPDATE',
            'Credentials',
            'Updated credential for '.($credential->employee?->full_name ?? 'Unknown Employee').'.',
            'Success',
            ['credential_id' => $credential->id, 'title' => $credential->title, 'fields' => array_keys($validated)]
        );

        return redirect()->route('admin.credentials.index')
            ->with('success', 'Credential updated successfully.');
    }

    public function deleteCredential(EmployeeCredential $credential): RedirectResponse
    {
        $employeeName = $credential->employee?->full_name ?? 'Unknown Employee';
        $credentialTitle = $credential->title;
        $credentialId = $credential->id;

        // Delete file from storage if exists
        if (!empty($credential->file_path) && $this->storage->isEnabled()) {
            $this->storage->delete($credential->file_path);
        }

        $credential->forceDelete();

        $this->logAudit('DELETE', 'Credentials', "Deleted credential for {$employeeName}.", 'Success', [
            'credential_id' => $credentialId,
            'title' => $credentialTitle,
        ]);

        return redirect()->route('admin.credentials.index')
            ->with('success', "Credential deleted for {$employeeName}.");
    }

    /**
     * ========== DTR / TIMEKEEPING EDITING (ADMIN-ONLY) ==========
     */

    public function dtrIndex(Request $request): View
    {
        $employeeId = $request->integer('employee_id');
        $month = $request->integer('month');
        $year = $request->integer('year');
        $recordStatus = $request->string('record_status')->toString() ?: 'all';
        $attendanceRange = $request->string('attendance_range')->toString() ?: 'month';

        if ($month && $year) {
            $dateFrom = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $dateTo = Carbon::createFromDate($year, $month, 1)->endOfMonth();
        } elseif ($request->filled('date_from') && $request->filled('date_to')) {
            $dateFrom = Carbon::createFromFormat('Y-m-d', $request->string('date_from')->toString())->startOfDay();
            $dateTo = Carbon::createFromFormat('Y-m-d', $request->string('date_to')->toString())->endOfDay();
        } else {
            $dateFrom = Carbon::now()->startOfMonth();
            $dateTo = Carbon::now()->endOfMonth();
        }

        $selectedMonth = $dateFrom->month;
        $selectedYear = $dateFrom->year;
        [$attendanceStart, $attendanceEnd] = match ($attendanceRange) {
            'first_half' => [$dateFrom->copy()->startOfMonth(), $dateFrom->copy()->startOfMonth()->day(15)->endOfDay()],
            'second_half' => [$dateFrom->copy()->startOfMonth()->day(16)->startOfDay(), $dateFrom->copy()->endOfMonth()],
            default => [$dateFrom->copy()->startOfMonth(), $dateFrom->copy()->endOfMonth()],
        };
        $employee = $employeeId ? Employee::query()->with('department')->findOrFail($employeeId) : null;
        $scheduleService = app(EmployeeScheduleService::class);

        $records = collect();
        $summary = [
            'present_days' => 0,
            'absent_days' => 0,
            'tardiness_total' => 0,
            'undertime_total' => 0,
        ];

        if ($employee) {
            $records = $this->buildAttendanceRows($employee, $dateFrom);
            if ($recordStatus !== 'all') {
                $records = $records->filter(function (array $record) use ($recordStatus): bool {
                    $normalizedStatus = strtolower(str_replace([' ', '-'], '_', (string) $record['status']));

                    return $normalizedStatus === $recordStatus;
                })->values();
            }
            $summary = [
                'present_days' => $records->where('status', 'Present')->count(),
                'absent_days' => $records->where('status', 'Not Present')->count(),
                'tardiness_total' => (int) $records->sum('tardiness_minutes'),
                'undertime_total' => (int) $records->sum('undertime_minutes'),
            ];
        }

        // Build employee cards similar to HR Timekeeping UI
        $employees = Employee::query()->orderBy('last_name')->orderBy('first_name')->get();
        $employeeIds = $employees->pluck('id');

        $approvedSchedules = EmployeeScheduleSubmission::query()
            ->with('days')
            ->whereIn('employee_id', $employeeIds)
            ->where('status', EmployeeScheduleSubmission::STATUS_APPROVED)
            ->orderByDesc('submitted_at')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($group) => $group->first());

        $approvedLeaves = LeaveRequest::query()
            ->whereIn('employee_id', $employeeIds)
            ->where('status', 'approved')
            ->whereDate('start_date', '<=', $dateTo->toDateString())
            ->whereDate('end_date', '>=', $dateFrom->toDateString())
            ->get()
            ->groupBy('employee_id');

        $attendanceStats = AttendanceRecord::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('record_date', [$attendanceStart->toDateString(), $attendanceEnd->toDateString()])
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($group) => [
                'present' => $group->where('status', 'present')->count(),
                'tardiness' => (int) $group->sum('tardiness_minutes'),
                'has_data' => $group->isNotEmpty(),
            ]);

        $employeeCards = $employees->map(function (Employee $emp) use ($attendanceStats, $dateFrom, $dateTo, $scheduleService, $approvedSchedules, $approvedLeaves) {
            $stats = $attendanceStats->get($emp->id, ['present' => 0, 'tardiness' => 0, 'has_data' => false]);
            $employeeApprovedSchedule = $approvedSchedules->get($emp->id);
            $employeeApprovedLeaves = $approvedLeaves->get($emp->id, collect());

            return [
                'id' => $emp->id,
                'initials' => str($emp->full_name)->explode(' ')->take(2)->map(fn ($part) => strtoupper(substr($part, 0, 1)))->join(''),
                'name' => $emp->full_name,
                'department' => $emp->department?->name ?? 'Unassigned',
                'present' => $stats['present'],
                'tardiness' => $stats['tardiness'],
                'absences' => $scheduleService->countDtrAbsencesWithContext($emp, $dateFrom, $dateTo, $employeeApprovedLeaves, $employeeApprovedSchedule),
                'has_data' => $stats['has_data'],
                'schedule_summary' => $scheduleService->summarizeSubmission($employeeApprovedSchedule),
                'weekly_work_hours' => $scheduleService->weeklyWorkHoursLabel($employeeApprovedSchedule),
            ];
        });

        $periods = collect();
        $systemStart = Carbon::create(2026, 4, 1)->startOfMonth();
        $currentMonth = now()->startOfMonth();
        for ($i = 0; $i <= $systemStart->diffInMonths($currentMonth); $i++) {
            $d = $currentMonth->copy()->subMonths($i);
            $periods->push([
                'label' => $d->format('F Y'),
                'month' => $d->month,
                'year' => $d->year,
                'selected' => $d->month === $selectedMonth && $d->year === $selectedYear,
            ]);
        }

        return view('admin.dtr.index', [
            'records' => $records,
            'latestDtrUploads' => AdminAuditLog::query()
                ->where('module', 'DTR')
                ->where('action', 'CREATE')
                ->where('status', 'Success')
                ->whereNotNull('metadata->original_filename')
                ->latest()
                ->limit(10)
                ->get(),
            'summary' => $summary,
            'employee' => $employee,
            'employees' => $employees,
            'dateFrom' => $dateFrom,
            'dateTo' => $dateTo,
            'selectedMonth' => $selectedMonth,
            'selectedYear' => $selectedYear,
            'periods' => $periods,
            'employeeCards' => $employeeCards,
            'scheduleSummary' => $employee ? $scheduleService->summarizeSubmission($scheduleService->approvedSubmissionForDate($employee, $dateFrom)) : null,
            'weeklyWorkHours' => $employee ? $scheduleService->weeklyWorkHoursLabel($scheduleService->approvedSubmissionForDate($employee, $dateFrom)) : 'No schedule available',
            'recordStatus' => $recordStatus,
            'attendanceRange' => $attendanceRange,
        ]);
    }

    public function exportDtrPdf(Request $request)
    {
        $employeeId = $request->integer('employee_id');
        $month = $request->integer('month', (int) now()->month);
        $year = $request->integer('year', (int) now()->year);
        $selectedDate = Carbon::createFromDate($year, $month, 1);

        $employee = Employee::query()->with('department')
            ->when($employeeId, fn ($query) => $query->whereKey($employeeId))
            ->orderBy('last_name')
            ->first();

        $records = $this->buildAttendanceRows($employee, $selectedDate);
        $summary = [
            'present_days' => $records->where('status', 'Present')->count(),
            'absent_days' => $records->where('status', 'Not Present')->count(),
            'tardiness_total' => (int) $records->sum('tardiness_minutes'),
            'undertime_total' => (int) $records->sum('undertime_minutes'),
        ];

        $scheduleService = app(EmployeeScheduleService::class);

        $pdf = \Barryvdh\DomPDF\Facade\Pdf::loadView('hr.dtr-export-pdf', [
            'employee' => $employee,
            'records' => $records,
            'summary' => $summary,
            'period_label' => $selectedDate->format('F Y'),
            'schedule_summary' => $scheduleService->summarizeSubmission($employee ? $scheduleService->approvedSubmissionForDate($employee, $selectedDate) : null),
        ]);

        $filename = 'DTR_' . str_replace(' ', '_', $employee?->full_name ?? 'Employee') . '_' . $selectedDate->format('F_Y') . '.pdf';

        $this->logAudit('EXPORT', 'DTR', 'Exported DTR PDF for '.($employee?->full_name ?? 'Employee').' ('.$selectedDate->format('F Y').').', 'Success', [
            'employee_id' => $employee?->employee_id,
            'period' => $selectedDate->format('Y-m'),
        ]);

        return $pdf->download($filename);
    }

    public function exportDtrExcel(Request $request)
    {
        $employeeId = $request->integer('employee_id');
        $month = $request->integer('month', (int) now()->month);
        $year = $request->integer('year', (int) now()->year);
        $selectedDate = Carbon::createFromDate($year, $month, 1);

        $employee = Employee::query()->with('department')
            ->when($employeeId, fn ($query) => $query->whereKey($employeeId))
            ->orderBy('last_name')
            ->first();

        $records = $this->buildAttendanceRows($employee, $selectedDate);

        $spreadsheet = new \PhpOffice\PhpSpreadsheet\Spreadsheet();
        $sheet = $spreadsheet->getActiveSheet();
        $sheet->setTitle('DTR');

        $sheet->setCellValue('A1', 'Daily Time Record - ' . ($employee?->full_name ?? 'Employee'));
        $sheet->setCellValue('A2', 'Period: ' . $selectedDate->format('F Y'));
        $sheet->mergeCells('A1:G1');
        $sheet->mergeCells('A2:G2');
        $sheet->getStyle('A1')->getFont()->setBold(true)->setSize(14);

        $headers = ['Date', 'Day', 'Time In', 'Time Out', 'Tardiness (min)', 'Undertime (min)', 'Status'];
        foreach ($headers as $col => $header) {
            $sheet->setCellValue(Coordinate::stringFromColumnIndex($col + 1) . '4', $header);
        }
        $sheet->getStyle('A4:G4')->getFont()->setBold(true);

        $row = 5;
        foreach ($records as $record) {
            $sheet->setCellValue('A' . $row, $record['date']);
            $sheet->setCellValue('B' . $row, $record['day']);
            $sheet->setCellValue('C' . $row, $record['time_in']);
            $sheet->setCellValue('D' . $row, $record['time_out']);
            $sheet->setCellValue('E' . $row, $record['tardiness_minutes'] ?: '-');
            $sheet->setCellValue('F' . $row, $record['undertime_minutes'] ?: '-');
            $sheet->setCellValue('G' . $row, $record['status']);
            $row++;
        }

        foreach (range('A', 'G') as $col) {
            $sheet->getColumnDimension($col)->setAutoSize(true);
        }

        $filename = 'DTR_' . str_replace(' ', '_', $employee?->full_name ?? 'Employee') . '_' . $selectedDate->format('F_Y') . '.xlsx';
        $writer = new Xlsx($spreadsheet);

        $this->logAudit('EXPORT', 'DTR', 'Exported DTR Excel for '.($employee?->full_name ?? 'Employee').' ('.$selectedDate->format('F Y').').', 'Success', [
            'employee_id' => $employee?->employee_id,
            'period' => $selectedDate->format('Y-m'),
        ]);

        return response()->streamDownload(function () use ($writer) {
            $writer->save('php://output');
        }, $filename, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    public function uploadDtr(Request $request, EmployeeScheduleService $scheduleService): RedirectResponse
    {
        @set_time_limit(180);

        $validated = $request->validate([
            'biometrics_file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
        ], [
            'biometrics_file.required' => 'Please choose a PDF file first.',
            'biometrics_file.mimes' => 'Only PDF files are accepted (got a different file type).',
            'biometrics_file.max' => 'The PDF is too large. Maximum allowed is 10 MB.',
        ]);

        $file = $validated['biometrics_file'];

        try {
            $extraction = app(BiometricPdfParser::class)->parseFile($file->getRealPath());
            $records = $extraction['records'];

            if (empty($records)) {
                $this->logAudit('CREATE', 'DTR', 'Failed to import biometric PDF. No attendance rows were detected.', 'Failed');

                return back()->with('error', 'No attendance rows were detected in the PDF. Upload a supported biometric Timesheet Report or Civil Service Form 48 Daily Time Record.');
            }

            $allEmployees = Employee::all();
            $byExactId = $allEmployees->keyBy('employee_id');
            $byNormalizedId = [];
            $byNameIndex = [];

            foreach ($allEmployees as $employee) {
                $normalizedId = $this->normalizeEmployeeId($employee->employee_id);
                $byNormalizedId[$normalizedId] = $employee;

                $lastKey = mb_strtolower(trim($employee->last_name));
                $byNameIndex[$lastKey][] = $employee;
            }

            $imported = 0;
            $skipped = 0;
            $unmatchedEmployees = [];
            $appliedEmployees = [];
            $matchedPairs = [];
            $matchedEmployees = [];
            $matchedDates = [];

            foreach ($records as $record) {
                $employee = $this->findEmployeeInMemory($record, $byExactId, $byNormalizedId, $byNameIndex);

                if (! $employee) {
                    $skipped++;
                    $label = trim(($record['name'] ?? '') . ' (' . ($record['employee_id'] ?? '—') . ')');
                    $unmatchedEmployees[$label] = true;
                    continue;
                }

                $matchedPairs[] = [$employee, $record];
                $matchedEmployees[$employee->id] = $employee;
                $matchedDates[] = $record['date'];
            }

            $scheduleService->warmImportCaches(array_values($matchedEmployees), $matchedDates);

            $now = now();
            $upsertRows = [];

            foreach ($matchedPairs as [$employee, $record]) {
                $recordDate = Carbon::parse($record['date']);
                $timeIn = ! empty($record['time_in']) ? Carbon::parse($record['time_in']) : null;
                $timeOut = ! empty($record['time_out']) ? Carbon::parse($record['time_out']) : null;

                $evaluation = $scheduleService->evaluateDailyRecord(
                    $employee,
                    $recordDate,
                    $record['time_in'] ?? null,
                    $record['time_out'] ?? null,
                );

                $upsertRows[] = [
                    'employee_id' => $employee->id,
                    'record_date' => $recordDate->toDateString(),
                    'time_in' => $timeIn?->format('H:i:s'),
                    'time_out' => $timeOut?->format('H:i:s'),
                    'scheduled_time_in' => $evaluation['scheduled_time_in'],
                    'scheduled_time_out' => $evaluation['scheduled_time_out'],
                    'tardiness_minutes' => $evaluation['tardiness_minutes'],
                    'undertime_minutes' => $evaluation['undertime_minutes'],
                    'overtime_minutes' => $evaluation['overtime_minutes'],
                    'schedule_status' => $evaluation['schedule_status'],
                    'schedule_notes' => $evaluation['schedule_notes'],
                    'status' => $evaluation['status'],
                    'created_at' => $now,
                    'updated_at' => $now,
                ];

                $imported++;
                $appliedEmployees[$employee->full_name . ' (' . $employee->employee_id . ')'] = true;
            }

            foreach (array_chunk($upsertRows, 200) as $chunk) {
                AttendanceRecord::upsert(
                    $chunk,
                    ['employee_id', 'record_date'],
                    [
                        'time_in',
                        'time_out',
                        'scheduled_time_in',
                        'scheduled_time_out',
                        'tardiness_minutes',
                        'undertime_minutes',
                        'overtime_minutes',
                        'schedule_status',
                        'schedule_notes',
                        'status',
                        'updated_at',
                    ]
                );
            }

            $message = "Biometric PDF processed — {$imported} attendance row(s) imported";
            if ($skipped > 0) {
                $message .= ", {$skipped} skipped (unmatched employees)";
            }
            $message .= '.';

            $redirect = redirect()->route('admin.dtr.index', [
                'month' => $request->integer('month', (int) now()->month),
                'year' => $request->integer('year', (int) now()->year),
                'employee_id' => $request->integer('employee_id') ?: null,
            ]);

            if (count($unmatchedEmployees) > 0) {
                $redirect = $redirect->with('unmatched_employees', array_keys($unmatchedEmployees));
            }

            if (count($appliedEmployees) > 0) {
                $redirect = $redirect->with('applied_employees', array_keys($appliedEmployees));
            }

            $this->logAudit('CREATE', 'DTR', $message, 'Success', [
                'original_filename' => $file->getClientOriginalName(),
                'imported' => $imported,
                'skipped' => $skipped,
                ...$this->extractionStats($extraction),
            ]);

            return $redirect->with('import_stats', [
                'imported' => $imported,
                'skipped' => $skipped,
                'total_records' => count($records),
                ...$this->extractionStats($extraction),
            ])->with('success', $message);
        } catch (\Throwable $e) {
            report($e);
            $this->logAudit('CREATE', 'DTR', 'Failed to process biometric PDF.', 'Failed');

            return back()->with('error', 'Failed to process biometric PDF. Please verify the file format and try again.');
        }
    }

    public function clearDtrCache(): RedirectResponse
    {
        $uploadLogs = AdminAuditLog::query()
            ->where('module', 'DTR')
            ->where('action', 'CREATE')
            ->where('status', 'Success')
            ->whereNotNull('metadata->original_filename');

        $deleted = $uploadLogs->delete();
        $entryLabel = $deleted === 1 ? 'entry' : 'entries';

        $this->logAudit('DELETE', 'DTR', "Cleared {$deleted} DTR upload cache {$entryLabel}.", 'Success', [
            'deleted_upload_entries' => $deleted,
            'attendance_records_affected' => false,
        ]);

        return redirect()->route('admin.dtr.index')
            ->with('success', $deleted > 0
                ? "Cleared {$deleted} DTR upload cache {$entryLabel}."
                : 'DTR upload cache is already clear. Attendance records were not changed.');
    }

    private function buildAttendanceRows(?Employee $employee, ?Carbon $selectedDate = null): Collection
    {
        $baseDate = $selectedDate ?? now();
        $periodStart = $baseDate->copy()->startOfMonth();
        $periodEnd = $baseDate->copy()->endOfMonth();

        if ($periodEnd->gt(now()->endOfDay())) {
            $periodEnd = now()->endOfDay();
        }

        if ($periodEnd->lt($periodStart)) {
            return collect();
        }

        $period = CarbonPeriod::create($periodStart, $periodEnd);

        $dbRecords = collect();
        $scheduleService = app(EmployeeScheduleService::class);

        if ($employee) {
            $dbRecords = AttendanceRecord::where('employee_id', $employee->id)
                ->whereBetween('record_date', [$periodStart, $periodEnd])
                ->get()
                ->keyBy(fn ($record) => $record->record_date->format('Y-m-d'));
        }

        return collect($period)
            ->map(function (Carbon $date) use ($employee, $dbRecords, $scheduleService) {
                $dateKey = $date->format('Y-m-d');
                $dayOfWeekIso = $date->dayOfWeekIso;

                if ($dayOfWeekIso === 7) {
                    return [
                        'iso_date' => $date->format('Y-m-d'),
                        'date' => $date->format('M j'),
                        'day' => $date->format('D'),
                        'time_in' => '-',
                        'time_out' => '-',
                        'tardiness_minutes' => 0,
                        'undertime_minutes' => 0,
                        'status' => 'Weekend',
                        'is_future' => $date->gt(now()->endOfDay()),
                    ];
                }

                if ($employee) {
                    $record = $dbRecords->get($dateKey);
                    $evaluation = $scheduleService->evaluateDailyRecord(
                        $employee,
                        $date,
                        $record?->time_in ? Carbon::parse($record->time_in)->format('H:i') : null,
                        $record?->time_out ? Carbon::parse($record->time_out)->format('H:i') : null,
                    );

                    $statusLabel = match ($evaluation['schedule_status']) {
                        'no_schedule' => 'No schedule available',
                        'non_working_day' => 'Non-working day',
                        default => ($evaluation['status'] === 'present') ? 'Present' : 'Not Present',
                    };

                    return [
                        'iso_date' => $date->format('Y-m-d'),
                        'date' => $date->format('M j'),
                        'day' => $date->format('D'),
                        'attendance_id' => $record?->id,
                        'time_in' => $record?->time_in ? Carbon::parse($record->time_in)->format('H:i') : '-',
                        'time_out' => $record?->time_out ? Carbon::parse($record->time_out)->format('H:i') : '-',
                        'tardiness_minutes' => $evaluation['tardiness_minutes'],
                        'undertime_minutes' => $evaluation['undertime_minutes'],
                        'schedule_status' => $evaluation['schedule_status'],
                        'schedule_notes' => $evaluation['schedule_notes'],
                        'status' => $statusLabel,
                        'status_reason' => $statusLabel === 'Present'
                            ? $scheduleService->presentReason($evaluation['schedule_notes'], $record?->schedule_notes)
                            : null,
                        'is_future' => $date->gt(now()->endOfDay()),
                    ];
                }

                if ($date->isAfter(now())) {
                    return [
                        'iso_date' => $date->format('Y-m-d'),
                        'date' => $date->format('M j'),
                        'day' => $date->format('D'),
                        'time_in' => '-',
                        'time_out' => '-',
                        'tardiness_minutes' => 0,
                        'undertime_minutes' => 0,
                        'status' => '-',
                        'is_future' => $date->gt(now()->endOfDay()),
                    ];
                }

                return [
                    'iso_date' => $date->format('Y-m-d'),
                    'date' => $date->format('M j'),
                    'day' => $date->format('D'),
                    'time_in' => '-',
                    'time_out' => '-',
                    'tardiness_minutes' => 0,
                    'undertime_minutes' => 0,
                    'status' => 'Not Present',
                    'is_future' => $date->gt(now()->endOfDay()),
                ];
            });
    }

    public function createDtrRecord(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'employee_id' => 'required|integer|exists:employees,id',
            'record_date' => 'required|date_format:Y-m-d',
        ]);

        $exists = AttendanceRecord::query()
            ->where('employee_id', $validated['employee_id'])
            ->whereDate('record_date', $validated['record_date'])
            ->exists();

        if (! $exists) {
            $record = AttendanceRecord::create([
                'employee_id' => $validated['employee_id'],
                'record_date' => $validated['record_date'],
                'time_in' => null,
                'time_out' => null,
                'tardiness_minutes' => 0,
                'undertime_minutes' => 0,
                'status' => 'absent',
            ]);

            $employeeName = $record->employee?->full_name ?? 'employee #'.$validated['employee_id'];
            $this->logAudit('CREATE', 'DTR', 'Created DTR record for '.$employeeName.' on '.$validated['record_date'].'.', 'Success', [
                'attendance_record_id' => $record->id,
                'record_date' => $validated['record_date'],
            ]);
        } else {
            $record = AttendanceRecord::query()
                ->where('employee_id', $validated['employee_id'])
                ->whereDate('record_date', $validated['record_date'])
                ->first();
        }

        return redirect()->route('admin.dtr.edit', $record->id);
    }

    public function editDtrRecord(AttendanceRecord $record): View
    {
        $employee = $record->employee;
        $absenceCount = 0;

        if ($employee && $record->record_date) {
            $scheduleService = app(EmployeeScheduleService::class);
            $monthStart = $record->record_date->copy()->startOfMonth();
            $monthEnd = $record->record_date->copy()->endOfMonth();

            $absenceCount = $scheduleService->countDtrAbsences($employee, $monthStart, $monthEnd);
        }

        return view('admin.dtr.edit', [
            'record' => $record,
            'absenceCount' => $absenceCount,
        ]);
    }

    public function updateDtrRecord(Request $request, AttendanceRecord $record): RedirectResponse
    {
        $validated = $request->validate([
            'time_in' => 'nullable|date_format:H:i',
            'time_out' => 'nullable|date_format:H:i',
            'status' => 'nullable|string|in:present,absent',
            'remarks' => 'nullable|string|max:500',
            'tardiness_minutes' => 'nullable|integer|min:0',
            'undertime_minutes' => 'nullable|integer|min:0',
        ]);

        $payload = [];

        if (array_key_exists('time_in', $validated)) {
            $payload['time_in'] = $validated['time_in'] ? (strlen($validated['time_in']) === 5 ? $validated['time_in'].':00' : $validated['time_in']) : null;
        }

        if (array_key_exists('time_out', $validated)) {
            $payload['time_out'] = $validated['time_out'] ? (strlen($validated['time_out']) === 5 ? $validated['time_out'].':00' : $validated['time_out']) : null;
        }

        $payload['status'] = $validated['status'] ?? $record->status;
        $payload['tardiness_minutes'] = isset($validated['tardiness_minutes']) ? (int) $validated['tardiness_minutes'] : ($record->tardiness_minutes ?? 0);
        $payload['undertime_minutes'] = isset($validated['undertime_minutes']) ? (int) $validated['undertime_minutes'] : ($record->undertime_minutes ?? 0);
        $payload['schedule_notes'] = $validated['remarks'] ?? $record->schedule_notes;

        $record->update($payload);

        $this->logAudit(
            'UPDATE',
            'DTR',
            'Updated DTR record for '.($record->employee?->full_name ?? 'an employee').' on '.$record->record_date?->toDateString().'.',
            'Success',
            ['attendance_record_id' => $record->id, 'record_date' => $record->record_date?->toDateString()]
        );

        return redirect()->route('admin.dtr.index')
            ->with('success', 'DTR record updated successfully.');
    }

    public function clearEmployeeDtr(Request $request, Employee $employee): RedirectResponse
    {
        [$dateFrom, $dateTo] = $this->resolveDtrDateRange($request);

        $deleted = AttendanceRecord::query()
            ->where('employee_id', $employee->id)
            ->whereBetween('record_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->delete();

        $this->logAudit('DELETE', 'DTR', "Cleared {$deleted} DTR record(s) for {$employee->full_name}.", 'Success', [
            'employee_id' => $employee->employee_id,
            'from' => $dateFrom->toDateString(),
            'to' => $dateTo->toDateString(),
            'deleted' => $deleted,
        ]);

        return redirect()->route('admin.dtr.index', [
            'employee_id' => $employee->id,
            'month' => $dateFrom->month,
            'year' => $dateFrom->year,
        ])->with('success', $deleted > 0
            ? "Cleared {$deleted} DTR record(s) for {$employee->full_name}."
            : "No DTR records found for {$employee->full_name} in the selected period.");
    }

    public function clearAllDtr(Request $request): RedirectResponse
    {
        [$dateFrom, $dateTo] = $this->resolveDtrDateRange($request);

        $deleted = AttendanceRecord::query()
            ->whereBetween('record_date', [$dateFrom->toDateString(), $dateTo->toDateString()])
            ->delete();

        $this->logAudit('DELETE', 'DTR', "Cleared {$deleted} DTR record(s) for the selected period.", 'Success', [
            'from' => $dateFrom->toDateString(),
            'to' => $dateTo->toDateString(),
            'deleted' => $deleted,
        ]);

        return redirect()->route('admin.dtr.index', [
            'month' => $dateFrom->month,
            'year' => $dateFrom->year,
        ])->with('success', $deleted > 0
            ? "Cleared {$deleted} DTR record(s) for the selected period."
            : 'No DTR records found for the selected period.');
    }

    /**
     * ========== WFH MANAGEMENT (ADMIN-ONLY) ==========
     */

    private function resolveDtrDateRange(Request $request): array
    {
        $month = $request->integer('month');
        $year = $request->integer('year');

        if ($month && $year) {
            $dateFrom = Carbon::createFromDate($year, $month, 1)->startOfMonth();
            $dateTo = Carbon::createFromDate($year, $month, 1)->endOfMonth();
        } elseif ($request->filled('date_from') && $request->filled('date_to')) {
            $dateFrom = Carbon::createFromFormat('Y-m-d', $request->string('date_from')->toString())->startOfDay();
            $dateTo = Carbon::createFromFormat('Y-m-d', $request->string('date_to')->toString())->endOfDay();
        } else {
            $dateFrom = Carbon::now()->startOfMonth();
            $dateTo = Carbon::now()->endOfMonth();
        }

        return [$dateFrom, $dateTo];
    }

    public function wfhIndex(Request $request): View
    {
        $search = $request->string('search')->toString();
        $departmentId = $request->string('department_id')->toString() ?: 'all';
        $statusFilter = $request->string('status')->toString() ?: 'all';
        $dateFrom = $request->filled('date_from') ? Carbon::parse($request->string('date_from')->toString())->startOfDay() : null;
        $dateTo = $request->filled('date_to') ? Carbon::parse($request->string('date_to')->toString())->endOfDay() : null;

        $submissions = WfhMonitoringSubmission::query()
            ->with(['employee.department', 'reviewer'])
            ->orderByDesc('wfh_date')
            ->orderByDesc('submitted_at')
            ->when($search !== '', function ($query) use ($search): void {
                $query->whereHas('employee', function ($employeeQuery) use ($search): void {
                    $employeeQuery->where('first_name', 'like', '%'.$search.'%')
                        ->orWhere('last_name', 'like', '%'.$search.'%')
                        ->orWhere('email', 'like', '%'.$search.'%')
                        ->orWhere('employee_id', 'like', '%'.$search.'%')
                        ->orWhereHas('department', fn ($departmentQuery) => $departmentQuery->where('name', 'like', '%'.$search.'%'));
                });
            })
            ->when($departmentId !== 'all', function ($query) use ($departmentId): void {
                if ($departmentId === 'asp') {
                    $query->whereHas('employee', function ($employeeQuery): void {
                        $employeeQuery->where('employment_type', 'Admin Support Personnel')
                            ->orWhereHas('department', fn ($departmentQuery) => $departmentQuery->where('name', 'ASP'));
                    });

                    return;
                }

                $query->whereHas('employee', fn ($employeeQuery) => $employeeQuery->where('department_id', $departmentId));
            })
            ->when($statusFilter !== 'all', fn ($query) => $query->where('status', $statusFilter))
            ->when($dateFrom && $dateTo, fn ($query) => $query->whereBetween('wfh_date', [$dateFrom->toDateString(), $dateTo->toDateString()]))
            ->get();

        $mapped = $submissions->map(function (WfhMonitoringSubmission $submission): array {
            return [
                'id' => $submission->id,
                'employee_name' => $submission->employee?->full_name ?? 'Unknown employee',
                'department' => $submission->employee?->department?->name ?? '—',
                'date' => $submission->wfh_date?->format('M d, Y') ?? '—',
                'time_in' => $submission->time_in?->format('h:i A') ?? '—',
                'time_out' => $submission->time_out?->format('h:i A') ?? '—',
                'status' => $submission->status,
                'status_label' => ucfirst($submission->status),
                'status_class' => match ($submission->status) {
                    WfhMonitoringSubmission::STATUS_APPROVED => 'bg-emerald-100 text-emerald-700',
                    WfhMonitoringSubmission::STATUS_DECLINED => 'bg-rose-100 text-rose-700',
                    default => 'bg-amber-100 text-amber-700',
                },
                'submitted_at' => $submission->submitted_at?->format('M d, Y h:i A') ?? '—',
                'reviewed_at' => $submission->reviewed_at?->format('M d, Y h:i A') ?? '—',
                'review_notes' => $submission->review_notes,
                'has_link' => filled($submission->monitoring_link),
                'monitoring_link' => $submission->monitoring_link,
            ];
        });

        return view('admin.wfh-monitoring.index', [
            'submissions' => $mapped,
            'departments' => Department::query()->facultySchools()->orderBy('name')->get(),
            'stats' => [
                'all' => $mapped->count(),
                'pending' => $mapped->where('status', WfhMonitoringSubmission::STATUS_PENDING)->count(),
                'approved' => $mapped->where('status', WfhMonitoringSubmission::STATUS_APPROVED)->count(),
                'declined' => $mapped->where('status', WfhMonitoringSubmission::STATUS_DECLINED)->count(),
            ],
            'filters' => [
                'search' => $search,
                'department_id' => $departmentId,
                'status' => $statusFilter,
                'date_from' => $request->string('date_from')->toString(),
                'date_to' => $request->string('date_to')->toString(),
            ],
        ]);
    }

    public function clearAllWfh(): RedirectResponse
    {
        $count = WfhMonitoringSubmission::query()->count();

        // Delete files from storage
        $submissions = WfhMonitoringSubmission::all();
        foreach ($submissions as $submission) {
            if (!empty($submission->file_path) && $this->storage->isEnabled()) {
                $this->storage->delete($submission->file_path);
            }
        }

        WfhMonitoringSubmission::query()->forceDelete();

        // Also remove corresponding attendance records
        AttendanceRecord::query()
            ->where('schedule_status', 'wfh')
            ->delete();

        $this->logAudit('DELETE', 'WFH Monitoring', "Cleared {$count} WFH record(s).", 'Success', [
            'count' => $count,
        ]);

        return redirect()->route('admin.wfh-monitoring.index')
            ->with('success', "Cleared {$count} WFH records and corresponding attendance.");
    }

    public function viewWfhFile(WfhMonitoringSubmission $submission): View|RedirectResponse
    {
        $employeeName = $submission->employee?->full_name ?? 'Unknown Employee';

        if (! $submission->file_path) {
            $this->logAudit('VIEW', 'WFH Monitoring', 'Failed to view WFH file for '.$employeeName.'. No file was attached.', 'Failed', [
                'submission_id' => $submission->id,
            ]);

            return back()->with('error', 'No file was attached to this WFH submission.');
        }

        if (! $this->storage->isEnabled()) {
            $this->logAudit('VIEW', 'WFH Monitoring', 'Failed to view WFH file for '.$employeeName.'. Storage is not configured.', 'Failed', [
                'submission_id' => $submission->id,
            ]);

            return back()->with('error', 'File storage is not configured. Please contact the administrator.');
        }

        $url = $this->storage->createSignedUrl($submission->file_path, 300);

        if (! $url) {
            $this->logAudit('VIEW', 'WFH Monitoring', 'Failed to view WFH file for '.$employeeName.'.', 'Failed', [
                'submission_id' => $submission->id,
            ]);

            return back()->with('error', 'Unable to generate a download link. Please try again.');
        }

        if (strtolower(pathinfo($submission->original_filename ?: $submission->file_path, PATHINFO_EXTENSION)) === 'pdf') {
            return redirect()->away($url);
        }

        $this->logAudit('VIEW', 'WFH Monitoring', 'Viewed WFH file for '.$employeeName.'.', 'Success', [
            'submission_id' => $submission->id,
        ]);

        return view('files.preview', [
            'url' => $url,
            'filename' => $submission->original_filename ?: basename($submission->file_path),
        ]);
    }

    public function deleteWfhSubmission(WfhMonitoringSubmission $submission): RedirectResponse
    {
        $employeeName = $submission->employee?->full_name ?? 'Unknown Employee';
        $wfhDate = $submission->wfh_date?->format('M d, Y') ?? 'Unknown Date';

        if (! empty($submission->file_path) && $this->storage->isEnabled()) {
            $this->storage->delete($submission->file_path);
        }

        $submission->forceDelete();

        $this->logAudit('DELETE', 'WFH Monitoring', "Deleted WFH submission for {$employeeName} on {$wfhDate}.", 'Success', [
            'employee' => $employeeName,
            'wfh_date' => $wfhDate,
        ]);

        return redirect()->route('admin.wfh-monitoring.index')
            ->with('success', "WFH submission deleted for {$employeeName} on {$wfhDate}.");
    }

    public function approveWfhSubmission(Request $request, WfhMonitoringSubmission $submission, EmployeeScheduleService $scheduleService): RedirectResponse
    {
        $validated = $request->validate([
            'confirmed' => ['accepted', 'in:1'],
            'review_notes' => ['nullable', 'string', 'max:1000'],
        ]);

        if ($submission->status === WfhMonitoringSubmission::STATUS_APPROVED) {
            $this->logAudit('APPROVE', 'WFH Monitoring', 'Failed to approve WFH submission. It is already approved.', 'Failed', [
                'submission_id' => $submission->id,
            ]);

            return back()->with('error', 'This WFH submission is already approved.');
        }

        $submission->loadMissing('employee');

        if (! $submission->employee) {
            return back()->with('error', 'The employee profile for this submission could not be found.');
        }

        DB::transaction(function () use ($request, $submission, $scheduleService, $validated): void {
            $submission->update([
                'status' => WfhMonitoringSubmission::STATUS_APPROVED,
                'reviewed_by' => $request->user()?->id,
                'reviewed_at' => now(),
                'review_notes' => $validated['review_notes'] ?? null,
            ]);

            $attendancePayload = [
                'time_in' => $submission->time_in?->format('H:i:s'),
                'time_out' => $submission->time_out?->format('H:i:s'),
                'scheduled_time_in' => $submission->time_in?->format('H:i:s'),
                'scheduled_time_out' => $submission->time_out?->format('H:i:s'),
                'tardiness_minutes' => 0,
                'undertime_minutes' => 0,
                'overtime_minutes' => 0,
                'schedule_status' => 'validated',
                'schedule_notes' => 'WFH approved by Admin',
                'status' => 'present',
            ];

            if ($submission->wfh_date && $submission->employee) {
                $existing = AttendanceRecord::query()->where('employee_id', $submission->employee_id)->whereDate('record_date', $submission->wfh_date)->first();

                if ($existing) {
                    $attendancePayload['scheduled_time_in'] = $submission->time_in?->format('H:i:s') ?? $existing->scheduled_time_in?->format('H:i:s');
                    $attendancePayload['scheduled_time_out'] = $submission->time_out?->format('H:i:s') ?? $existing->scheduled_time_out?->format('H:i:s');
                    $attendancePayload['time_in'] = $submission->time_in?->format('H:i:s') ?? $existing->time_in?->format('H:i:s');
                    $attendancePayload['time_out'] = $submission->time_out?->format('H:i:s') ?? $existing->time_out?->format('H:i:s');
                }
            }

            AttendanceRecord::updateOrCreate(
                [
                    'employee_id' => $submission->employee_id,
                    'record_date' => $submission->wfh_date?->toDateString(),
                ],
                $attendancePayload
            );

            $announcement = Announcement::forceCreate([
                'title' => 'WFH monitoring approved',
                'content' => sprintf('Your WFH monitoring sheet for %s was approved by Admin.', $submission->wfh_date?->format('F d, Y') ?? 'your submitted date'),
                'priority' => 'medium',
                'target_user_type' => User::TYPE_EMPLOYEE,
                'published_at' => now(),
                'is_published' => true,
                'created_by' => $request->user()?->id,
            ]);

            $employeeUser = User::query()->where('email', $submission->employee->email)->first();

            if ($employeeUser) {
                AnnouncementNotification::create([
                    'announcement_id' => $announcement->id,
                    'user_id' => $employeeUser->id,
                    'is_read' => false,
                    'read_at' => null,
                    'redirect_url' => route('employee.attendance'),
                ]);
            }
        });

        $this->logAudit(
            'APPROVE',
            'WFH Monitoring',
            'Approved WFH submission for '.($submission->employee?->full_name ?? 'an employee').' on '.($submission->wfh_date?->format('M d, Y') ?? 'an unknown date').'.',
            'Success',
            ['submission_id' => $submission->id]
        );

        return back()->with('success', 'WFH submission approved and attendance was updated.');
    }

    public function declineWfhSubmission(Request $request, WfhMonitoringSubmission $submission): RedirectResponse
    {
        $validated = $request->validate([
            'confirmed' => ['accepted', 'in:1'],
            'review_notes' => ['required', 'string', 'max:1000'],
        ]);

        if ($submission->status === WfhMonitoringSubmission::STATUS_DECLINED) {
            $this->logAudit('DECLINE', 'WFH Monitoring', 'Failed to decline WFH submission. It is already declined.', 'Failed', [
                'submission_id' => $submission->id,
            ]);

            return back()->with('error', 'This WFH submission is already declined.');
        }

        $submission->loadMissing('employee');

        $submission->update([
            'status' => WfhMonitoringSubmission::STATUS_DECLINED,
            'reviewed_by' => $request->user()?->id,
            'reviewed_at' => now(),
            'review_notes' => $validated['review_notes'],
        ]);

        $employeeUser = $submission->employee
            ? User::query()->where('email', $submission->employee->email)->first()
            : null;

        if ($employeeUser) {
            $announcement = Announcement::forceCreate([
                'title' => 'WFH monitoring declined',
                'content' => sprintf('Your WFH monitoring sheet for %s was declined by Admin. Notes: %s', $submission->wfh_date?->format('F d, Y') ?? 'your submitted date', $validated['review_notes']),
                'priority' => 'medium',
                'target_user_type' => User::TYPE_EMPLOYEE,
                'published_at' => now(),
                'is_published' => true,
                'created_by' => $request->user()?->id,
            ]);

            AnnouncementNotification::create([
                'announcement_id' => $announcement->id,
                'user_id' => $employeeUser->id,
                'is_read' => false,
                'read_at' => null,
                'redirect_url' => route('employee.wfh-monitoring.index'),
            ]);
        }

        $this->logAudit(
            'DECLINE',
            'WFH Monitoring',
            'Declined WFH submission for '.($submission->employee?->full_name ?? 'an employee').' on '.($submission->wfh_date?->format('M d, Y') ?? 'an unknown date').'.',
            'Success',
            ['submission_id' => $submission->id]
        );

        return back()->with('success', 'WFH submission declined. The employee has been notified.');
    }

    /**
     * ========== LEAVE MANAGEMENT (ADMIN-ONLY) ==========
     */

    public function leaveIndex(Request $request): View
    {
        $search = $request->string('search')->toString();
        $departmentId = $request->string('department_id')->toString();
        $employeeClass = $request->string('employee_class')->toString() ?: 'all';
        $leaveMonitoringService = app(LeaveMonitoringService::class);
        $leaveBalanceService = app(LeaveBalanceService::class);

        $employeesQuery = Employee::query()
            ->with([
                'department',
                'leaveRequests',
            ])
            ->when($search, function ($query, $searchTerm) {
                $query->where(function ($nested) use ($searchTerm) {
                    $nested
                        ->where('employee_id', 'like', "%{$searchTerm}%")
                        ->orWhere('first_name', 'like', "%{$searchTerm}%")
                        ->orWhere('last_name', 'like', "%{$searchTerm}%")
                        ->orWhere('email', 'like', "%{$searchTerm}%")
                        ->orWhereHas('department', fn ($department) => $department->where('name', 'like', "%{$searchTerm}%"));
                });
            })
            ->when($departmentId === 'asp', function ($query) {
                $query->where(function ($nested) {
                    $nested
                        ->where('employment_type', 'Admin Support Personnel')
                        ->orWhereHas('department', fn ($department) => $department->where('name', 'ASP'));
                });
            })
            ->when(filled($departmentId) && $departmentId !== 'asp', fn ($query) => $query->where('department_id', $departmentId))
            ->orderBy('last_name')
            ->orderBy('first_name');

        $leaveMonitoringService->applyEmployeeClassFilter($employeesQuery, $employeeClass);

        $employees = $employeesQuery->get();

        $employees->each(function (Employee $employee) use ($leaveBalanceService) {
            $leaveBalanceService->initializeOrUpdateBalance($employee);
            $employee->unsetRelation('leaveBalances');
            $employee->load('leaveBalances');
        });

        // Pre-aggregate absence counts for the current year to avoid N+1 queries
        $yearStart = now()->startOfYear()->toDateString();
        $yearEnd = now()->endOfYear()->toDateString();
        $absencesByEmployee = AttendanceRecord::query()
            ->whereIn('employee_id', $employees->pluck('id'))
            ->whereBetween('record_date', [$yearStart, $yearEnd])
            ->where('status', 'absent')
            ->get()
            ->groupBy('employee_id')
            ->map(fn ($group) => $group->count());

        $leaveCards = $employees->map(function (Employee $employee) use ($absencesByEmployee, $leaveBalanceService) {
            $balances = $employee->leaveBalances;
            $today = now()->startOfDay();
            $hireDate = $employee->hire_date ? Carbon::parse($employee->hire_date)->startOfDay() : null;
            $calendarYearStart = $leaveBalanceService->usagePeriodStart($employee);
            $leaveMonitoringService = app(LeaveMonitoringService::class);
            $isRegularEmployee = $leaveMonitoringService->isRegularEmployee($employee, $today);

            $requests = $employee->leaveRequests->filter(function (LeaveRequest $request) use ($hireDate) {
                if (! $request->start_date) {
                    return false;
                }

                $startDate = $request->start_date->copy()->startOfDay();

                // Approved upcoming leave is already deducted from the balance,
                // so it counts as used too; only leave before hire is ignored.
                return ! ($hireDate && $startDate->lt($hireDate));
            });

            $approvedRequests = $requests
                ->where('status', 'approved')
                ->filter(fn (LeaveRequest $request) => $request->start_date?->gte($calendarYearStart));
            $usedByType = $approvedRequests->groupBy(fn ($r) => strtolower((string) $r->leave_type));

            $vacationUsed = (float) collect(['vacation leave', 'vacation', 'vl'])
                ->flatMap(fn ($key) => $usedByType->get($key, collect()))
                ->sum('days_deducted');

            $sickUsed = (float) collect(['sick leave', 'sick', 'sl'])
                ->flatMap(fn ($key) => $usedByType->get($key, collect()))
                ->sum('days_deducted');

            $emergencyUsed = (float) collect(['emergency leave', 'emergency', 'el'])
                ->flatMap(fn ($key) => $usedByType->get($key, collect()))
                ->sum('days_deducted');

            $used = $vacationUsed + $sickUsed + $emergencyUsed;
            $remaining = (float) $balances->sum('remaining_days');

            $balanceByType = $balances->keyBy(fn ($b) => strtolower((string) $b->leave_type));
            $lookup = function (array $keys) use ($balanceByType) {
                foreach ($keys as $key) {
                    if ($balanceByType->has($key)) {
                        return (float) $balanceByType->get($key)->remaining_days;
                    }
                }

                return null;
            };

            return [
                'id' => $employee->id,
                'name' => $employee->full_name,
                'department' => $employee->department?->name ?? 'Unassigned',
                'initials' => str($employee->full_name)->explode(' ')->take(2)->map(fn ($part) => strtoupper(substr($part, 0, 1)))->join(''),
                'vacation_remaining' => $lookup(['vacation leave', 'vacation', 'vl']),
                'vacation_used' => $vacationUsed,
                'sick_remaining' => $lookup(['sick leave', 'sick', 'sl']),
                'sick_used' => $sickUsed,
                'emergency_remaining' => $lookup(['emergency leave', 'emergency', 'el']),
                'emergency_used' => $emergencyUsed,
                'remaining' => $remaining,
                'used' => $used,
                'carry_over' => 0,
                'has_data' => $requests->isNotEmpty() || $balances->isNotEmpty(),
                'leave_types' => $usedByType->keys()->toArray(),
                'employee_status' => $isRegularEmployee ? 'regular' : 'non-regular',
                'employee_status_label' => $isRegularEmployee ? 'Full - Time' : 'Probationary',
                'absences' => $absencesByEmployee->get($employee->id, 0),
            ];
        });

        $totalUsed = (float) $leaveCards->sum('used');
        $totalVacationUsed = (float) $leaveCards->sum('vacation_used');
        $totalSickUsed = (float) $leaveCards->sum('sick_used');

        $systemStart = Carbon::create(2026, 4, 1)->startOfMonth();
        $currentMonth = now()->startOfMonth();
        $monthOptions = collect(range(0, $systemStart->diffInMonths($currentMonth)))
            ->map(function ($offset) use ($currentMonth) {
                $date = $currentMonth->copy()->subMonths($offset);

                return [
                    'value' => $date->format('Y-m'),
                    'label' => $date->format('F Y'),
                ];
            })
            ->values();

        return view('admin.leave-management.index', [
            'leaveCards' => $leaveCards,
            'latestLeaveUploads' => AdminAuditLog::query()
                ->where('module', 'Leave Management')
                ->where('action', 'CREATE')
                ->where('status', 'Success')
                ->whereNotNull('metadata->original_filename')
                ->latest()
                ->limit(10)
                ->get(),
            'departments' => Department::query()->facultySchools()->orderBy('name')->get(),
            'filters' => [
                'search' => $search,
                'department_id' => $departmentId,
                'employee_class' => $employeeClass,
            ],
            'monthOptions' => $monthOptions,
            'stats' => [
                'total_employees' => $leaveCards->count(),
                'vacation_used' => (int) round($totalVacationUsed),
                'sick_used' => (int) round($totalSickUsed),
                'current_year' => now()->year,
            ],
        ]);
    }

    public function uploadLeaves(Request $request, LeaveMonitoringService $leaveMonitoringService): RedirectResponse
    {
        $request->validate([
            'leaves_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [
            'leaves_file.required' => 'Please choose an Excel file first.',
            'leaves_file.mimes' => 'Only .xlsx or .xls files are accepted.',
            'leaves_file.max' => 'The file is too large. Maximum allowed is 10 MB.',
        ]);

        // Admin and HR share one leave import so the same file always gives the same result.
        return app(HrOperationsController::class)->processLeaveUpload($request, $leaveMonitoringService);
    }

    public function clearLeaveFileCache(): RedirectResponse
    {
        $uploadLogs = AdminAuditLog::query()
            ->where('module', 'Leave Management')
            ->where('action', 'CREATE')
            ->where('status', 'Success')
            ->whereNotNull('metadata->original_filename');

        $deleted = $uploadLogs->delete();
        $entryLabel = $deleted === 1 ? 'entry' : 'entries';

        $this->logAudit('DELETE', 'Leave Management', "Cleared {$deleted} leave file cache {$entryLabel}.", 'Success', [
            'deleted_upload_entries' => $deleted,
            'leave_records_affected' => false,
        ]);

        return redirect()->route('admin.leave.index')
            ->with('success', $deleted > 0
                ? "Cleared {$deleted} leave file cache {$entryLabel}."
                : 'Leave file cache is already clear. Leave records were not changed.');
    }

    private function logAudit(string $action, string $module, string $description, string $status = 'Success', array $metadata = []): void
    {
        app(AuditLogService::class)->record($action, $module, $description, $status, $metadata);
    }

    private function normalizeEmployeeId(string $id): string
    {
        return Employee::normalizeEmployeeId($id);
    }

    /**
     * Extraction details shown after a biometric upload: which PDF layout was
     * recognised, how many pages and rows were read, and how long it took.
     *
     * @return array{format:string, pages:int, rows_extracted:int, extraction_seconds:float}
     */
    private function extractionStats(array $extraction): array
    {
        return [
            'format' => $extraction['format_label'],
            'pages' => $extraction['pages'],
            'rows_extracted' => count($extraction['records']),
            'extraction_seconds' => $extraction['seconds'],
        ];
    }

    /**
     * @param Collection<int, string> $byExactId
     * @param array<string, Employee> $byNormalizedId
     * @param array<string, array<int, Employee>> $byNameIndex
     */
    private function findEmployeeInMemory(
        array $record,
        Collection $byExactId,
        array $byNormalizedId,
        array $byNameIndex
    ): ?Employee {
        return EmployeeRecordMatcher::find($record, $byExactId, $byNormalizedId, $byNameIndex);
    }
}
