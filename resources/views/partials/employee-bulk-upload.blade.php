{{--
    Bulk employee upload controls, shared by the HR and Admin Employees pages.
    Expects: $latestBulkUploads, $bulkUploadRoute, optional $bulkClearRoute (Admin only).
--}}
@php
    $bulkClearRoute = $bulkClearRoute ?? null;
    $bulkResult = session('bulk_upload_result');
@endphp

<div class="flex flex-wrap items-center justify-end gap-2">
    <div class="flex max-w-[24rem] items-center gap-2 text-xs text-slate-500">
        <span class="whitespace-nowrap">Latest uploaded file:</span>
        <select class="min-w-0 truncate rounded-md border border-slate-300 bg-white px-2 py-2 text-xs text-slate-700" aria-label="Latest uploaded employee files">
            @forelse ($latestBulkUploads as $upload)
                <option>{{ $upload->metadata['original_filename'] }} ({{ $upload->created_at->format('M j, Y g:i A') }}){{ isset($upload->metadata['verification']['field_accuracy']) ? ' · '.$upload->metadata['verification']['field_accuracy'].'% verified' : '' }}</option>
            @empty
                <option>No uploaded employee files</option>
            @endforelse
        </select>
    </div>
    <button type="button" data-bulk-upload-open class="rounded-lg border border-[#00386f] bg-white px-4 py-2 text-sm font-semibold text-[#00386f] hover:bg-blue-50">Bulk Upload Employee</button>
    @if ($bulkClearRoute)
        <form method="POST" action="{{ $bulkClearRoute }}" onsubmit="return confirm('Clear the bulk upload history? Employee records will not be affected.');">
            @csrf
            <button type="submit" class="rounded-lg border border-amber-300 bg-amber-50 px-4 py-2 text-sm font-semibold text-amber-800 transition hover:bg-amber-100">Clear Upload History</button>
        </form>
    @endif
</div>

<div id="bulk-upload-modal" class="fixed inset-0 z-50 hidden items-start justify-center overflow-y-auto bg-black/45 p-4 py-6 sm:items-center">
    <div class="w-full max-w-2xl rounded-2xl bg-white shadow-2xl">
        <div class="flex items-start justify-between px-7 py-6">
            <div>
                <h3 class="text-3xl font-bold text-[#1f2b8b]">Bulk Upload Employees</h3>
                <p class="mt-1 text-sm text-slate-500">Excel file (.xlsx / .xls), one employee per row. Max 10 MB.</p>
            </div>
            <button type="button" data-bulk-upload-close class="text-4xl leading-none text-slate-500 hover:text-slate-700">&times;</button>
        </div>
        <form id="bulk-upload-form" class="px-7 pb-7" method="POST" action="{{ $bulkUploadRoute }}" enctype="multipart/form-data">
            @csrf
            <div class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-800">
                <p class="font-semibold">Required columns</p>
                <p class="mt-1 text-blue-700">Employee ID, Email, First Name, Last Name, Employee Type, Employment Status, Department, Rank, Level, Hire Date.</p>
                <p class="mt-1 text-blue-700">Employees already registered are skipped by Employee ID, with or without dash (23-8041 = 238041). New employees receive their login credentials by email.</p>
            </div>
            <label for="employees_file" class="block cursor-pointer rounded-lg border-2 border-dashed border-slate-300 p-8 text-center transition hover:border-[#00386f] hover:bg-blue-50/30">
                <input id="employees_file" name="employees_file" type="file" accept=".xlsx,.xls,application/vnd.openxmlformats-officedocument.spreadsheetml.sheet,application/vnd.ms-excel" class="sr-only" required>
                <div class="mx-auto inline-flex h-14 w-14 items-center justify-center text-slate-400">
                    <svg class="h-10 w-10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"><path d="M12 16V4" /><path d="m7 9 5-5 5 5" /><path d="M4 16v3a1 1 0 0 0 1 1h14a1 1 0 0 0 1-1v-3" /></svg>
                </div>
                <p id="bulk-upload-file-name" class="mt-3 text-lg text-slate-500">Click to choose an Excel file</p>
                <p class="text-sm text-slate-400">.xlsx or .xls · up to 10 MB</p>
            </label>
            <div class="mt-6 flex flex-col gap-3 sm:flex-row sm:justify-end">
                <button type="button" data-bulk-upload-close class="rounded-md border border-slate-400 px-6 py-2.5 font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                <button type="submit" id="bulk-upload-submit" class="rounded-md bg-[#00386f] px-6 py-2.5 font-semibold text-white hover:bg-[#002f5d] disabled:cursor-wait disabled:opacity-60">Upload Employees</button>
            </div>
        </form>
    </div>
</div>

@if ($bulkResult)
    <div id="bulk-upload-result-modal" class="fixed inset-0 z-50 flex items-start justify-center overflow-y-auto bg-black/45 p-4 py-6 sm:items-center">
        <div class="max-h-[90vh] w-full max-w-2xl overflow-y-auto rounded-2xl bg-white shadow-2xl">
            <div class="flex items-start justify-between border-b border-slate-300 px-6 py-4">
                <div>
                    <h3 class="text-2xl font-bold text-[#1f2b5d]">Bulk Upload Results</h3>
                    <p class="text-sm text-slate-500">{{ $bulkResult['filename'] }} · {{ $bulkResult['total_rows'] }} row(s)</p>
                </div>
                <button type="button" data-bulk-result-close class="text-4xl leading-none text-slate-500 hover:text-slate-700">&times;</button>
            </div>
            <div class="space-y-5 px-6 py-5">
                <div class="grid grid-cols-3 gap-3">
                    <div class="rounded-lg border border-emerald-200 bg-emerald-50 p-3 text-center"><p class="text-3xl font-extrabold text-emerald-700">{{ count($bulkResult['created']) }}</p><p class="text-xs font-semibold text-emerald-700">Added</p></div>
                    <div class="rounded-lg border border-slate-200 bg-slate-50 p-3 text-center"><p class="text-3xl font-extrabold text-slate-700">{{ count($bulkResult['existing']) }}</p><p class="text-xs font-semibold text-slate-600">Already registered</p></div>
                    <div class="rounded-lg border border-red-200 bg-red-50 p-3 text-center"><p class="text-3xl font-extrabold text-red-700">{{ count($bulkResult['failed']) }}</p><p class="text-xs font-semibold text-red-700">With errors</p></div>
                </div>

                @include('partials.import-verification', ['verification' => $bulkResult['verification'] ?? null])

                @if (count($bulkResult['created']) > 0)
                    <p class="text-sm text-slate-600">Login accounts for new employees are being created and their credentials emailed in the background. This can take a few minutes for large files.</p>
                @endif

                @if (count($bulkResult['failed']) > 0)
                    <div>
                        <h4 class="mb-2 text-sm font-bold text-red-700">Rows with errors (not added)</h4>
                        <ul class="divide-y divide-red-100 rounded-lg border border-red-200 text-sm">
                            @foreach ($bulkResult['failed'] as $row)
                                <li class="px-3 py-2">
                                    <span class="font-semibold">Row {{ $row['row'] }}</span>
                                    @if ($row['employee_id'] !== '' || $row['name'] !== '')
                                        · {{ $row['employee_id'] }} {{ $row['name'] }}
                                    @endif
                                    <p class="text-xs text-red-700">{{ $row['reason'] }}</p>
                                </li>
                            @endforeach
                        </ul>
                    </div>
                @endif

                @if (count($bulkResult['existing']) > 0)
                    <details>
                        <summary class="cursor-pointer text-sm font-bold text-slate-700">Already registered, skipped ({{ count($bulkResult['existing']) }})</summary>
                        <ul class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                            @foreach ($bulkResult['existing'] as $row)
                                <li class="px-3 py-2">Row {{ $row['row'] }} · {{ $row['employee_id'] }} {{ $row['name'] }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                @if (count($bulkResult['created']) > 0)
                    <details>
                        <summary class="cursor-pointer text-sm font-bold text-emerald-700">Added ({{ count($bulkResult['created']) }})</summary>
                        <ul class="mt-2 divide-y divide-slate-100 rounded-lg border border-slate-200 text-sm">
                            @foreach ($bulkResult['created'] as $row)
                                <li class="px-3 py-2">Row {{ $row['row'] }} · {{ $row['employee_id'] }} {{ $row['name'] }}</li>
                            @endforeach
                        </ul>
                    </details>
                @endif

                <div class="flex justify-end">
                    <button type="button" data-bulk-result-close class="rounded-md bg-[#00386f] px-6 py-2 font-semibold text-white hover:bg-[#002f5d]">Done</button>
                </div>
            </div>
        </div>
    </div>
@endif

<script @cspNonce>
    (function () {
        const modal = document.getElementById('bulk-upload-modal');
        const fileInput = document.getElementById('employees_file');
        const fileName = document.getElementById('bulk-upload-file-name');
        const form = document.getElementById('bulk-upload-form');
        const submit = document.getElementById('bulk-upload-submit');
        const resultModal = document.getElementById('bulk-upload-result-modal');

        const toggle = (element, open) => {
            if (!element) return;
            element.classList.toggle('hidden', !open);
            element.classList.toggle('flex', open);
        };

        document.querySelectorAll('[data-bulk-upload-open]').forEach((button) => {
            button.addEventListener('click', () => toggle(modal, true));
        });
        document.querySelectorAll('[data-bulk-upload-close]').forEach((button) => {
            button.addEventListener('click', () => toggle(modal, false));
        });
        document.querySelectorAll('[data-bulk-result-close]').forEach((button) => {
            button.addEventListener('click', () => toggle(resultModal, false));
        });

        fileInput?.addEventListener('change', () => {
            const file = fileInput.files?.[0];
            fileName.textContent = file ? file.name : 'Click to choose an Excel file';
        });

        form?.addEventListener('submit', () => {
            submit.disabled = true;
            submit.textContent = 'Uploading… please wait';
        });
    })();
</script>
