@extends('admin.layout')

@section('title', 'Extraction Validation')
@section('hide_page_heading', '1')

@php
    use App\Models\ExtractionValidationRun;

    $pageTitle = 'Extraction Validation';
    $pageHeading = 'PDF Extraction Validation';
@endphp

@section('content')
    <div class="flex flex-col gap-3 lg:flex-row lg:items-start lg:justify-between">
        <div class="max-w-3xl">
            <a href="{{ route('admin.dtr.index') }}" class="text-sm font-semibold text-[#00386f] hover:underline">&larr; Back to DTR Management</a>
            <p class="mt-2 text-sm text-slate-600">
                Measures how accurately the biometric PDF parser reads attendance. Each run compares what the system extracts from a PDF against a
                <strong>ground truth</strong> file encoded by hand from the same PDF. Runs use the same parser as DTR uploads and
                <strong>never change attendance records</strong>.
            </p>
        </div>
        <div class="flex flex-wrap gap-2">
            <a href="{{ route('admin.dtr.validation.template') }}" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Download Ground Truth Template</a>
            @if ($runs->isNotEmpty())
                <a href="{{ route('admin.dtr.validation.summary-report') }}" class="rounded-lg bg-[#00386f] px-4 py-2 text-sm font-semibold text-white hover:bg-[#002f5d]">Download Summary Report (PDF)</a>
            @endif
        </div>
    </div>

    <article class="rounded-xl border border-slate-300 bg-white p-5 shadow-sm">
        <h3 class="text-lg font-bold text-[#1f2b5d]">Run a validation</h3>
        <form id="validation-form" method="POST" action="{{ route('admin.dtr.validation.store') }}" enctype="multipart/form-data" class="mt-4 grid grid-cols-1 gap-4 md:grid-cols-[1fr_1fr_auto] md:items-end">
            @csrf
            <div>
                <label for="pdf_file" class="mb-1 block text-sm font-semibold text-slate-700">1. Biometric PDF</label>
                <input id="pdf_file" name="pdf_file" type="file" accept=".pdf,application/pdf" required class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-sm">
            </div>
            <div>
                <label for="ground_truth_file" class="mb-1 block text-sm font-semibold text-slate-700">2. Ground truth (.xlsx)</label>
                <input id="ground_truth_file" name="ground_truth_file" type="file" accept=".xlsx,.xls" required class="block w-full rounded-md border border-slate-300 px-3 py-2 text-sm file:mr-3 file:rounded file:border-0 file:bg-slate-100 file:px-3 file:py-1 file:text-sm">
            </div>
            <button id="validation-submit" type="submit" class="rounded-lg bg-[#00386f] px-6 py-2.5 text-sm font-semibold text-white hover:bg-[#002f5d] disabled:cursor-wait disabled:opacity-60">Run Validation</button>
        </form>
    </article>

    @if ($overall)
        <div class="flex items-end justify-between gap-3">
            <div>
                <h3 class="text-xl font-bold text-[#1f2b5d]">Overall results</h3>
                <p class="text-sm text-slate-500">All {{ $runs->count() }} validation run(s) combined · {{ number_format($overall->expected_rows) }} ground truth rows</p>
            </div>
        </div>
        @include('admin.dtr.extraction-validation._metrics', ['run' => $overall])
    @endif

    <article class="overflow-x-auto rounded-xl border border-slate-300 bg-white shadow-sm">
        <div class="border-b border-slate-200 px-4 py-3">
            <h3 class="font-bold text-[#1f2b5d]">Validation history</h3>
        </div>
        <table class="w-full min-w-[760px] text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                <tr>
                    <th class="px-4 py-3">Date</th>
                    <th class="px-4 py-3">PDF</th>
                    <th class="px-4 py-3">Format</th>
                    <th class="px-4 py-3 text-right">Rows</th>
                    <th class="px-4 py-3 text-right">Recall</th>
                    <th class="px-4 py-3 text-right">Fully correct</th>
                    <th class="px-4 py-3 text-right">F1</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse ($runs as $run)
                    <tr class="hover:bg-slate-50">
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $run->created_at->format('M j, Y g:i A') }}</td>
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-900">{{ $run->pdf_filename }}</p>
                            <p class="text-xs text-slate-500">by {{ $run->user?->name ?? 'System' }}</p>
                        </td>
                        <td class="px-4 py-3 text-slate-600">{{ $run->format }}</td>
                        <td class="px-4 py-3 text-right text-slate-600">{{ number_format($run->expected_rows) }}</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ ExtractionValidationRun::percent($run->matched_rows, $run->expected_rows) ?? '—' }}%</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ ExtractionValidationRun::percent($run->fully_correct_rows, $run->expected_rows) ?? '—' }}%</td>
                        <td class="px-4 py-3 text-right font-semibold">{{ $run->f1Score() ?? '—' }}%</td>
                        <td class="whitespace-nowrap px-4 py-3 text-right">
                            <a href="{{ route('admin.dtr.validation.show', $run) }}" class="font-semibold text-[#00386f] hover:underline">View</a>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="8" class="px-4 py-8 text-center text-slate-500">No validation runs yet. Download the ground truth template, encode a biometric PDF by hand, then run a validation above.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </article>

    <script @cspNonce>
        document.getElementById('validation-form')?.addEventListener('submit', () => {
            const submit = document.getElementById('validation-submit');
            submit.disabled = true;
            submit.textContent = 'Validating…';
        });
    </script>
@endsection
