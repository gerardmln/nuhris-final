@extends('hr.layout')

@php
    $pageTitle = 'Employee Profile';
    $pageHeading = 'Employee Profile';
    $activeNav = 'employees';
@endphp

@section('content')
    <a href="{{ route('employees.index') }}" class="inline-flex items-center gap-2 text-sm font-semibold text-slate-700 hover:text-slate-900">
        <span>&larr;</span>
        Back to Employees
    </a>

    <section class="rounded-xl border border-slate-300 bg-white p-6 shadow-sm">
        @if ($employee)
            <div class="flex flex-col gap-4 md:flex-row md:items-center md:justify-between">
                <div>
                    <h2 class="text-3xl font-bold text-[#1f2b5d]">{{ $employee->full_name }}</h2>
                    <p class="text-sm text-slate-500">{{ $employee->position ?? 'No position set' }}</p>
                </div>
                <span class="rounded-md bg-emerald-100 px-3 py-1 text-xs font-semibold text-emerald-700">{{ ucfirst($employee->status) }}</span>
            </div>

            <dl class="mt-5 grid grid-cols-1 gap-3 text-sm sm:grid-cols-2 lg:grid-cols-3">
                <div><dt class="font-semibold">Email</dt><dd>{{ $employee->email }}</dd></div>
                <div><dt class="font-semibold">Department</dt><dd>{{ $employee->department->name ?? 'Unassigned' }}</dd></div>
                <div><dt class="font-semibold">Hired</dt><dd>{{ optional($employee->hire_date)->format('M d, Y') ?? 'N/A' }}</dd></div>
                <div><dt class="font-semibold">Approved Schedule</dt><dd>{{ $schedule_summary }}</dd></div>
                <div><dt class="font-semibold">Employee ID</dt><dd>{{ $employee->employee_id }}</dd></div>
            </dl>
        @else
            <p class="text-slate-500">No employee record found.</p>
        @endif
    </section>

    @if ($employee)
        <section class="rounded-xl border border-slate-300 bg-white p-6 shadow-sm">
            <div>
                <h2 class="text-2xl font-bold text-[#1f2b5d]">Academic Degrees</h2>
                <p class="mt-1 text-sm text-slate-500">Review employee-submitted degree certificates.</p>
            </div>

            <div class="mt-5 grid grid-cols-1 gap-4 lg:grid-cols-3">
                @foreach (['undergraduate' => 'Undergraduate Degree', 'masters' => "Master's Degree", 'doctoral' => 'Doctoral Degree'] as $level => $label)
                    @php($degree = $degrees->firstWhere('degree_level', $level))
                    <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                        <h3 class="font-bold text-slate-900">{{ $label }}</h3>
                        @if ($degree)
                            <p class="mt-3 truncate text-sm text-slate-600" title="{{ $degree->original_filename }}">{{ $degree->original_filename }}</p>
                            <p class="mt-1 text-xs text-slate-500">Status: {{ ucfirst($degree->status) }}</p>
                            <a href="{{ route('employees.degrees.view', $degree) }}" class="mt-3 inline-flex rounded-lg border border-blue-300 bg-white px-3 py-2 text-xs font-semibold text-blue-700 hover:bg-blue-50">View PDF</a>
                            @if ($degree->status === 'pending')
                                <div class="mt-4 flex flex-wrap gap-2">
                                    <form method="POST" action="{{ route('employees.degrees.approve', $degree) }}">
                                        @csrf
                                        <button type="submit" class="rounded-lg bg-emerald-600 px-3 py-2 text-xs font-semibold text-white hover:bg-emerald-700">Approve</button>
                                    </form>
                                    <form method="POST" action="{{ route('employees.degrees.decline', $degree) }}" class="flex flex-1 gap-2" onsubmit="return confirm('Decline this submission? It will be deleted and the employee will be notified.');">
                                        @csrf
                                        <input name="review_notes" required maxlength="1000" placeholder="Reason for decline" class="min-w-0 flex-1 rounded-lg border border-slate-300 px-2 py-2 text-xs">
                                        <button type="submit" class="rounded-lg bg-red-600 px-3 py-2 text-xs font-semibold text-white hover:bg-red-700">Decline</button>
                                    </form>
                                </div>
                            @endif
                        @else
                            <p class="mt-3 text-sm text-slate-500">No submission.</p>
                        @endif
                    </div>
                @endforeach
            </div>
        </section>
    @endif
@endsection
