<?php

namespace App\Http\Controllers\Concerns;

use App\Jobs\ProvisionImportedEmployeeAccounts;
use App\Models\AdminAuditLog;
use App\Services\AuditLogService;
use App\Services\EmployeeBulkImportService;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

trait HandlesEmployeeBulkUpload
{
    public function bulkUpload(Request $request, EmployeeBulkImportService $importer): RedirectResponse
    {
        // Report file problems as a flash error: the HR Employees page reopens
        // the Add Employee modal whenever the error bag is not empty.
        $validator = Validator::make($request->all(), [
            'employees_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [
            'employees_file.required' => 'Please choose an Excel file first.',
            'employees_file.mimes' => 'Only .xlsx or .xls files are accepted.',
            'employees_file.max' => 'The file is too large. Maximum allowed is 10 MB.',
        ]);

        if ($validator->fails()) {
            return back()->with('error', $validator->errors()->first());
        }

        $file = $request->file('employees_file');
        $originalFilename = $file->getClientOriginalName();
        @set_time_limit(300);

        try {
            $result = $importer->import($file);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('Bulk employee upload failed', ['file' => $originalFilename, 'error' => $exception->getMessage()]);

            return back()->with('error', 'The Excel file could not be read. Please check that it follows the employee upload template.');
        }

        $created = count($result['created']);
        $existing = count($result['existing']);
        $failed = count($result['failed']);

        $message = "Bulk upload processed {$result['total_rows']} row(s): {$created} added, {$existing} already registered, {$failed} with errors.";

        app(AuditLogService::class)->record('CREATE', 'Employees', $message, 'Success', [
            'original_filename' => $originalFilename,
            'bulk_upload' => true,
            'total_rows' => $result['total_rows'],
            'created' => $created,
            'existing' => $existing,
            'failed' => $failed,
        ]);

        if ($result['created_employee_ids'] !== []) {
            ProvisionImportedEmployeeAccounts::dispatchAfterResponse(
                $result['created_employee_ids'],
                $request->user()?->id,
                $originalFilename,
            );
        }

        return back()
            ->with('success', $message)
            ->with('bulk_upload_result', [
                'filename' => $originalFilename,
                'total_rows' => $result['total_rows'],
                'created' => $result['created'],
                'existing' => $result['existing'],
                'failed' => $result['failed'],
            ]);
    }

    /**
     * @return Collection<int, AdminAuditLog>
     */
    protected function latestBulkUploads(): Collection
    {
        return $this->bulkUploadLogQuery()->latest()->limit(10)->get();
    }

    protected function bulkUploadLogQuery()
    {
        return AdminAuditLog::query()
            ->where('module', 'Employees')
            ->where('action', 'CREATE')
            ->whereNotNull('metadata->original_filename');
    }
}
