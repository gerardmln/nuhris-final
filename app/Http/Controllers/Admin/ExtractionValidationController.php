<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\ExtractionValidationRun;
use App\Services\AuditLogService;
use App\Services\ExtractionValidationService;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\View\View;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Measures biometric PDF extraction accuracy against hand-encoded ground
 * truth. Runs never write attendance records.
 */
class ExtractionValidationController extends Controller
{
    public function index(): View
    {
        $runs = ExtractionValidationRun::query()->with('user')->latest()->get();

        return view('admin.dtr.extraction-validation.index', [
            'runs' => $runs,
            'overall' => $this->overall($runs),
        ]);
    }

    public function store(Request $request, ExtractionValidationService $validator): RedirectResponse
    {
        $validation = Validator::make($request->all(), [
            'pdf_file' => ['required', 'file', 'mimes:pdf', 'max:10240'],
            'ground_truth_file' => ['required', 'file', 'mimes:xlsx,xls', 'max:10240'],
        ], [
            'pdf_file.required' => 'Choose the biometric PDF to validate.',
            'pdf_file.mimes' => 'The biometric file must be a PDF.',
            'ground_truth_file.required' => 'Choose the ground truth Excel file.',
            'ground_truth_file.mimes' => 'The ground truth must be an .xlsx or .xls file.',
        ]);

        if ($validation->fails()) {
            return back()->with('error', $validation->errors()->first());
        }

        @set_time_limit(180);

        try {
            $run = $validator->run($request->file('pdf_file'), $request->file('ground_truth_file'), $request->user()?->id);
        } catch (\InvalidArgumentException $exception) {
            return back()->with('error', $exception->getMessage());
        } catch (\Throwable $exception) {
            Log::error('Extraction validation failed', ['error' => $exception->getMessage()]);

            return back()->with('error', 'Validation could not be completed. Check that the PDF is a biometric DTR and the ground truth follows the template.');
        }

        app(AuditLogService::class)->record('CREATE', 'DTR', sprintf(
            'Ran PDF extraction validation on %s: %s%% of %d rows fully correct.',
            $run->pdf_filename,
            ExtractionValidationRun::percent($run->fully_correct_rows, $run->expected_rows) ?? 0,
            $run->expected_rows,
        ), 'Success', ['validation_run_id' => $run->id]);

        return redirect()->route('admin.dtr.validation.show', $run)
            ->with('success', 'Validation complete. No attendance records were changed.');
    }

    public function show(ExtractionValidationRun $run): View
    {
        return view('admin.dtr.extraction-validation.show', ['run' => $run->load('user')]);
    }

    public function destroy(ExtractionValidationRun $run): RedirectResponse
    {
        $run->delete();

        return redirect()->route('admin.dtr.validation.index')->with('success', 'Validation run deleted.');
    }

    public function report(ExtractionValidationRun $run): Response
    {
        return Pdf::loadView('admin.dtr.extraction-validation.report-pdf', [
            'runs' => collect([$run]),
            'overall' => $this->overall(collect([$run])),
            'showMismatches' => true,
        ])->download('extraction-validation-'.$run->id.'.pdf');
    }

    public function summaryReport(): Response
    {
        $runs = ExtractionValidationRun::query()->with('user')->oldest()->get();

        return Pdf::loadView('admin.dtr.extraction-validation.report-pdf', [
            'runs' => $runs,
            'overall' => $this->overall($runs),
            'showMismatches' => false,
        ])->download('extraction-validation-summary-'.now()->format('Y-m-d').'.pdf');
    }

    public function template(ExtractionValidationService $validator): StreamedResponse
    {
        $writer = new Xlsx($validator->template());

        return response()->streamDownload(fn () => $writer->save('php://output'), 'ground-truth-template.xlsx', [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        ]);
    }

    /**
     * Totals across runs, as a single pseudo-run so the same metrics apply.
     */
    private function overall(Collection $runs): ?ExtractionValidationRun
    {
        if ($runs->isEmpty()) {
            return null;
        }

        return new ExtractionValidationRun([
            'pages' => $runs->sum('pages'),
            'extraction_seconds' => round($runs->sum('extraction_seconds'), 2),
            'expected_rows' => $runs->sum('expected_rows'),
            'extracted_rows' => $runs->sum('extracted_rows'),
            'matched_rows' => $runs->sum('matched_rows'),
            'time_in_correct' => $runs->sum('time_in_correct'),
            'time_out_correct' => $runs->sum('time_out_correct'),
            'fully_correct_rows' => $runs->sum('fully_correct_rows'),
            'employee_matched_rows' => $runs->sum('employee_matched_rows'),
        ]);
    }
}
