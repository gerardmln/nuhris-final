{{-- PDF extraction details shown in the biometric upload result. Expects: $importStats --}}
@if (! empty($importStats['format']))
    <div class="mb-5 rounded-lg border border-slate-200 bg-slate-50 px-4 py-3 text-sm">
        <p class="mb-2 text-xs font-bold uppercase tracking-wide text-slate-500">PDF extraction</p>
        <dl class="grid grid-cols-2 gap-x-4 gap-y-1 sm:grid-cols-4">
            <div><dt class="text-xs text-slate-500">Format detected</dt><dd class="font-semibold text-slate-800">{{ $importStats['format'] }}</dd></div>
            <div><dt class="text-xs text-slate-500">Pages read</dt><dd class="font-semibold text-slate-800">{{ $importStats['pages'] ?? '—' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Rows found in PDF</dt><dd class="font-semibold text-slate-800">{{ $importStats['rows_extracted'] ?? '—' }}</dd></div>
            <div><dt class="text-xs text-slate-500">Extraction time</dt><dd class="font-semibold text-slate-800">{{ isset($importStats['extraction_seconds']) ? number_format($importStats['extraction_seconds'], 2).'s' : '—' }}</dd></div>
        </dl>
    </div>
@endif
