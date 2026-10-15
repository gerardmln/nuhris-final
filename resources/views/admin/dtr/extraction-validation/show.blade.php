@extends('admin.layout')

@section('title', 'Validation Result')
@section('hide_page_heading', '1')

@php
    $pageTitle = 'Extraction Validation';
    $pageHeading = 'PDF Extraction Validation';
    $mismatches = collect($run->mismatches ?? []);
    $typeLabels = [
        'missing' => ['Missed row', 'bg-red-100 text-red-700'],
        'extra' => ['False row', 'bg-purple-100 text-purple-700'],
        'time_in' => ['Wrong time in', 'bg-amber-100 text-amber-800'],
        'time_out' => ['Wrong time out', 'bg-amber-100 text-amber-800'],
    ];
    $counts = $mismatches->countBy('type');
@endphp

@section('content')
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div>
            <a href="{{ route('admin.dtr.validation.index') }}" class="text-sm font-semibold text-[#00386f] hover:underline">&larr; All validation runs</a>
            <h2 class="mt-2 text-2xl font-bold text-[#1f2b5d]">{{ $run->pdf_filename }}</h2>
            <p class="text-sm text-slate-500">
                Format detected: <strong>{{ $run->format }}</strong> ·
                Ground truth: {{ $run->ground_truth_filename }} ·
                {{ $run->created_at->format('M j, Y g:i A') }} by {{ $run->user?->name ?? 'System' }}
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.dtr.validation.report', $run) }}" class="rounded-lg bg-[#00386f] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002f5d]">Download Report (PDF)</a>
            <form method="POST" action="{{ route('admin.dtr.validation.destroy', $run) }}" onsubmit="return confirm('Delete this validation run?');">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-600 hover:bg-slate-50">Delete</button>
            </form>
        </div>
    </div>

    @include('admin.dtr.extraction-validation._metrics', ['run' => $run])

    <article class="overflow-x-auto rounded-xl border border-slate-300 bg-white shadow-sm">
        <div class="flex flex-wrap items-center justify-between gap-2 border-b border-slate-200 px-4 py-3">
            <h3 class="font-bold text-[#1f2b5d]">Mismatches ({{ $mismatches->count() }})</h3>
            <div class="flex flex-wrap gap-2 text-xs">
                @foreach ($typeLabels as $type => [$label, $classes])
                    <span class="rounded-full px-2.5 py-1 font-semibold {{ $classes }}">{{ $label }}: {{ $counts[$type] ?? 0 }}</span>
                @endforeach
            </div>
        </div>
        @if ($mismatches->isEmpty())
            <p class="px-4 py-8 text-center font-semibold text-emerald-700">Every ground truth row was extracted correctly.</p>
        @else
            <table class="w-full min-w-[760px] text-sm">
                <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                    <tr>
                        <th class="px-4 py-3">Issue</th>
                        <th class="px-4 py-3">GT row</th>
                        <th class="px-4 py-3">Employee</th>
                        <th class="px-4 py-3">Date</th>
                        <th class="px-4 py-3">Field</th>
                        <th class="px-4 py-3">Expected (ground truth)</th>
                        <th class="px-4 py-3">Extracted (system)</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-slate-200">
                    @foreach ($mismatches as $mismatch)
                        @php([$label, $classes] = $typeLabels[$mismatch['type']] ?? [$mismatch['type'], 'bg-slate-100 text-slate-700'])
                        <tr>
                            <td class="px-4 py-2"><span class="whitespace-nowrap rounded-full px-2.5 py-0.5 text-xs font-semibold {{ $classes }}">{{ $label }}</span></td>
                            <td class="px-4 py-2 text-slate-500">{{ $mismatch['row'] ?? '—' }}</td>
                            <td class="px-4 py-2">{{ $mismatch['employee'] }}</td>
                            <td class="whitespace-nowrap px-4 py-2">{{ \Carbon\Carbon::parse($mismatch['date'])->format('M j, Y') }}</td>
                            <td class="px-4 py-2">{{ $mismatch['field'] }}</td>
                            <td class="px-4 py-2 font-semibold text-emerald-700">{{ $mismatch['expected'] }}</td>
                            <td class="px-4 py-2 font-semibold text-red-700">{{ $mismatch['extracted'] }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        @endif
    </article>
@endsection
