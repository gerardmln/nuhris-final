{{-- Accuracy metrics for one run (or the overall totals). Expects: $run (ExtractionValidationRun) --}}
@php
    use App\Models\ExtractionValidationRun;

    $barColor = fn (?float $percent) => match (true) {
        $percent === null => 'bg-slate-300',
        $percent >= 95 => 'bg-emerald-500',
        $percent >= 85 => 'bg-amber-500',
        default => 'bg-red-500',
    };
    $textColor = fn (?float $percent) => match (true) {
        $percent === null => 'text-slate-400',
        $percent >= 95 => 'text-emerald-700',
        $percent >= 85 => 'text-amber-700',
        default => 'text-red-700',
    };
    $f1 = $run->f1Score();
@endphp

<div class="grid grid-cols-2 gap-3 md:grid-cols-4">
    <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
        <p class="text-xs text-slate-500">F1 score</p>
        <p class="text-3xl font-extrabold {{ $textColor($f1) }}">{{ $f1 !== null ? number_format($f1, 2).'%' : '—' }}</p>
        <p class="text-xs text-slate-400">Balance of precision and recall</p>
    </article>
    <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
        <p class="text-xs text-slate-500">Ground truth rows</p>
        <p class="text-3xl font-extrabold text-slate-900">{{ number_format($run->expected_rows) }}</p>
        <p class="text-xs text-slate-400">{{ number_format($run->extracted_rows) }} extracted by the parser</p>
    </article>
    <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
        <p class="text-xs text-slate-500">False rows</p>
        <p class="text-3xl font-extrabold {{ $run->falseRows() > 0 ? 'text-red-700' : 'text-slate-900' }}">{{ number_format($run->falseRows()) }}</p>
        <p class="text-xs text-slate-400">Extracted but not in the PDF</p>
    </article>
    <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
        <p class="text-xs text-slate-500">Processing time</p>
        <p class="text-3xl font-extrabold text-slate-900">{{ number_format($run->extraction_seconds, 2) }}s</p>
        <p class="text-xs text-slate-400">{{ number_format($run->pages) }} page(s)</p>
    </article>
</div>

<article class="rounded-xl border border-slate-300 bg-white shadow-sm">
    <table class="w-full text-sm">
        <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
            <tr>
                <th class="px-4 py-3">Metric</th>
                <th class="px-4 py-3 text-right">Correct / Total</th>
                <th class="px-4 py-3 text-right">Result</th>
                <th class="hidden w-1/3 px-4 py-3 md:table-cell"></th>
            </tr>
        </thead>
        <tbody class="divide-y divide-slate-200">
            @foreach ($run->metrics() as $metric)
                @php($percent = ExtractionValidationRun::percent($metric['correct'], $metric['total']))
                <tr>
                    <td class="px-4 py-3">
                        <p class="font-semibold text-slate-900">{{ $metric['label'] }}</p>
                        <p class="text-xs text-slate-500">{{ $metric['help'] }}</p>
                    </td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-slate-600">{{ number_format($metric['correct']) }} / {{ number_format($metric['total']) }}</td>
                    <td class="whitespace-nowrap px-4 py-3 text-right text-lg font-extrabold {{ $textColor($percent) }}">{{ $percent !== null ? number_format($percent, 2).'%' : '—' }}</td>
                    <td class="hidden px-4 py-3 md:table-cell">
                        <div class="h-2.5 w-full overflow-hidden rounded-full bg-slate-100">
                            <div class="h-full rounded-full {{ $barColor($percent) }}" style="width: {{ $percent ?? 0 }}%"></div>
                        </div>
                    </td>
                </tr>
            @endforeach
        </tbody>
    </table>
</article>
