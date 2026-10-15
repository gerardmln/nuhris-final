@php
    use App\Models\ExtractionValidationRun;

    $pct = fn (int $correct, int $total) => ($p = ExtractionValidationRun::percent($correct, $total)) !== null ? number_format($p, 2).'%' : '—';
@endphp
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>PDF Extraction Validation Report</title>
    <style>
        body { font-family: DejaVu Sans, sans-serif; font-size: 10px; color: #0f172a; }
        h1 { font-size: 16px; color: #1f2b5d; margin: 0; }
        h2 { font-size: 12px; color: #1f2b5d; margin: 18px 0 6px; }
        .muted { color: #64748b; }
        table { width: 100%; border-collapse: collapse; margin-top: 4px; }
        th, td { border: 1px solid #cbd5e1; padding: 4px 6px; text-align: left; }
        th { background: #f1f5f9; }
        .num { text-align: right; }
        .strong { font-weight: bold; }
    </style>
</head>
<body>
    <h1>NU HRIS — PDF Extraction Validation Report</h1>
    <p class="muted">
        Generated {{ now()->format('F j, Y g:i A') }}.
        Biometric DTR PDFs were parsed by the system and compared row by row against ground truth encoded by hand from the same PDFs.
    </p>

    @if ($overall)
        <h2>{{ $runs->count() > 1 ? 'Overall results ('.$runs->count().' files)' : 'Results' }}</h2>
        <table>
            <tr><th>Metric</th><th class="num">Correct / Total</th><th class="num">Result</th></tr>
            @foreach ($overall->metrics() as $metric)
                <tr>
                    <td>{{ $metric['label'] }}<br><span class="muted">{{ $metric['help'] }}</span></td>
                    <td class="num">{{ number_format($metric['correct']) }} / {{ number_format($metric['total']) }}</td>
                    <td class="num strong">{{ $pct($metric['correct'], $metric['total']) }}</td>
                </tr>
            @endforeach
            <tr>
                <td>F1 score<br><span class="muted">Harmonic mean of precision and recall.</span></td>
                <td class="num">—</td>
                <td class="num strong">{{ $overall->f1Score() !== null ? number_format($overall->f1Score(), 2).'%' : '—' }}</td>
            </tr>
            <tr>
                <td>False rows<br><span class="muted">Extracted rows not present in the PDF.</span></td>
                <td class="num">—</td>
                <td class="num strong">{{ number_format($overall->falseRows()) }}</td>
            </tr>
            <tr>
                <td>Processing time<br><span class="muted">Total PDF reading and parsing time.</span></td>
                <td class="num">{{ number_format($overall->pages) }} page(s)</td>
                <td class="num strong">{{ number_format($overall->extraction_seconds, 2) }} s</td>
            </tr>
        </table>
    @endif

    <h2>Files validated</h2>
    <table>
        <tr>
            <th>Date</th><th>PDF</th><th>Format detected</th><th class="num">GT rows</th><th class="num">Extracted</th>
            <th class="num">Recall</th><th class="num">Precision</th><th class="num">Time in</th><th class="num">Time out</th><th class="num">Fully correct</th>
        </tr>
        @foreach ($runs as $run)
            <tr>
                <td>{{ $run->created_at?->format('Y-m-d') }}</td>
                <td>{{ $run->pdf_filename }}</td>
                <td>{{ $run->format }}</td>
                <td class="num">{{ $run->expected_rows }}</td>
                <td class="num">{{ $run->extracted_rows }}</td>
                <td class="num">{{ $pct($run->matched_rows, $run->expected_rows) }}</td>
                <td class="num">{{ $pct($run->matched_rows, $run->extracted_rows) }}</td>
                <td class="num">{{ $pct($run->time_in_correct, $run->matched_rows) }}</td>
                <td class="num">{{ $pct($run->time_out_correct, $run->matched_rows) }}</td>
                <td class="num strong">{{ $pct($run->fully_correct_rows, $run->expected_rows) }}</td>
            </tr>
        @endforeach
    </table>

    @if ($showMismatches)
        @foreach ($runs as $run)
            <h2>Mismatches — {{ $run->pdf_filename }} ({{ count($run->mismatches ?? []) }})</h2>
            @if (empty($run->mismatches))
                <p>Every ground truth row was extracted correctly.</p>
            @else
                <table>
                    <tr><th>Issue</th><th>GT row</th><th>Employee</th><th>Date</th><th>Field</th><th>Expected</th><th>Extracted</th></tr>
                    @foreach ($run->mismatches as $mismatch)
                        <tr>
                            <td>{{ ['missing' => 'Missed row', 'extra' => 'False row', 'time_in' => 'Wrong time in', 'time_out' => 'Wrong time out'][$mismatch['type']] ?? $mismatch['type'] }}</td>
                            <td>{{ $mismatch['row'] ?? '—' }}</td>
                            <td>{{ $mismatch['employee'] }}</td>
                            <td>{{ $mismatch['date'] }}</td>
                            <td>{{ $mismatch['field'] }}</td>
                            <td>{{ $mismatch['expected'] }}</td>
                            <td>{{ $mismatch['extracted'] }}</td>
                        </tr>
                    @endforeach
                </table>
            @endif
        @endforeach
    @endif

    <h2>Method</h2>
    <p>
        Recall = ground truth rows found ÷ ground truth rows. Precision = extracted rows found in the ground truth ÷ extracted rows.
        Time-in / time-out accuracy are measured on detected rows. A row is fully correct when its date, time in and time out all match.
        Rows are paired by date and employee (Employee ID, or name when the PDF format has no IDs). Times are compared to the minute.
    </p>
</body>
</html>
