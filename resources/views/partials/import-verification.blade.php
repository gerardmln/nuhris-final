{{-- Post-import data verification (saved records vs. the uploaded file). Expects: $verification --}}
@if (! empty($verification))
    @php
        $fieldAccuracy = $verification['field_accuracy'];
        $tone = match (true) {
            $fieldAccuracy === null => ['border-slate-200 bg-slate-50', 'text-slate-600'],
            $fieldAccuracy >= 99.995 => ['border-emerald-200 bg-emerald-50', 'text-emerald-700'],
            $fieldAccuracy >= 95 => ['border-amber-200 bg-amber-50', 'text-amber-700'],
            default => ['border-red-200 bg-red-50', 'text-red-700'],
        };
    @endphp
    <div class="mb-5 rounded-lg border {{ $tone[0] }} px-4 py-3 text-sm">
        <div class="flex flex-wrap items-baseline justify-between gap-2">
            <p class="text-xs font-bold uppercase tracking-wide text-slate-500">Data verification</p>
            <p class="text-xs text-slate-500">Saved records re-checked against the uploaded file</p>
        </div>
        <dl class="mt-2 grid grid-cols-1 gap-x-4 gap-y-2 sm:grid-cols-3">
            <div>
                <dt class="text-xs text-slate-500">Field accuracy</dt>
                <dd class="text-2xl font-extrabold {{ $tone[1] }}">{{ $fieldAccuracy !== null ? number_format($fieldAccuracy, 2).'%' : '—' }}</dd>
                <dd class="text-xs text-slate-500">{{ number_format($verification['fields_correct']) }} / {{ number_format($verification['fields_checked']) }} fields</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Fully correct records</dt>
                <dd class="text-2xl font-extrabold {{ $tone[1] }}">{{ $verification['record_accuracy'] !== null ? number_format($verification['record_accuracy'], 2).'%' : '—' }}</dd>
                <dd class="text-xs text-slate-500">{{ number_format($verification['fully_correct']) }} / {{ number_format($verification['records_checked']) }} records</dd>
            </div>
            <div>
                <dt class="text-xs text-slate-500">Records found in database</dt>
                <dd class="text-2xl font-extrabold text-slate-800">{{ number_format($verification['records_found']) }} / {{ number_format($verification['records_checked']) }}</dd>
                @if (($verification['not_imported'] ?? 0) > 0 || ($verification['superseded'] ?? 0) > 0)
                    <dd class="text-xs text-slate-500">
                        @if ($verification['not_imported'] > 0){{ $verification['not_imported'] }} row(s) not imported (unregistered employee / no date). @endif
                        @if ($verification['superseded'] > 0){{ $verification['superseded'] }} replaced by a later row. @endif
                    </dd>
                @endif
            </div>
        </dl>

        @if (! empty($verification['mismatches']))
            <details class="mt-3">
                <summary class="cursor-pointer text-sm font-bold text-red-700">Mismatches ({{ $verification['mismatch_count'] }})</summary>
                <div class="mt-2 max-h-60 overflow-auto rounded border border-red-200 bg-white">
                    <table class="w-full text-xs">
                        <thead class="bg-red-50 text-left text-red-800">
                            <tr><th class="px-2 py-1">Row</th><th class="px-2 py-1">Record</th><th class="px-2 py-1">Field</th><th class="px-2 py-1">In file</th><th class="px-2 py-1">Saved</th></tr>
                        </thead>
                        <tbody class="divide-y divide-red-100">
                            @foreach ($verification['mismatches'] as $mismatch)
                                <tr>
                                    <td class="px-2 py-1">{{ $mismatch['row'] }}</td>
                                    <td class="px-2 py-1">{{ $mismatch['label'] }}</td>
                                    <td class="px-2 py-1">{{ $mismatch['field'] }}</td>
                                    <td class="px-2 py-1 font-semibold text-emerald-700">{{ $mismatch['file'] }}</td>
                                    <td class="px-2 py-1 font-semibold text-red-700">{{ $mismatch['saved'] }}</td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </details>
        @endif
    </div>
@endif
