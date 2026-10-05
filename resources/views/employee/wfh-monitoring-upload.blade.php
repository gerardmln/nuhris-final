@extends('employee.layout')

@section('title', 'Submit WFH Submission')
@section('page_title', 'WFH Submission')

@section('content')
    <div class="flex flex-wrap items-center justify-between gap-4">
        <p class="text-sm text-slate-600">Submit the link to your <span class="font-semibold">WORK OUTPUT MONITORING SHEET</span> for a WFH day.</p>
        <a href="{{ route('employee.wfh-monitoring.index') }}" class="rounded-xl bg-[#242b34] px-5 py-2 text-sm font-semibold text-white hover:bg-[#1b222b]">Cancel</a>
    </div>

    @if (session('error'))
        <div class="rounded-lg border border-red-300 bg-red-50 px-4 py-3 text-sm text-red-800">{{ session('error') }}</div>
    @endif

    <article class="rounded-2xl border border-slate-300 bg-white p-6 shadow-sm">
        <div class="max-w-3xl">
            <h2 class="text-3xl font-bold text-slate-900">Submit WFH Sheet Link</h2>
            <p class="mt-2 text-sm text-slate-600">HR will review the submitted link first, then approve or decline it based on the selected WFH date.</p>
            <p class="mt-2 text-sm text-slate-600">Time in and time out must be more than 2 hours apart, and times between 11:00 PM and 4:00 AM are not allowed.</p>
        </div>

        <form method="POST" action="{{ route('employee.wfh-monitoring.store') }}" class="mt-6 grid grid-cols-1 gap-4 lg:grid-cols-4">
            @csrf

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">WFH Date</label>
                <input type="date" name="wfh_date" value="{{ old('wfh_date') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('wfh_date')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Time In</label>
                <input type="time" name="time_in" value="{{ old('time_in') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('time_in')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div>
                <label class="mb-2 block text-sm font-semibold text-slate-700">Time Out</label>
                <input type="time" name="time_out" value="{{ old('time_out') }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                @error('time_out')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="lg:col-span-4">
                <label class="mb-2 block text-sm font-semibold text-slate-700">Monitoring Sheet Link</label>
                <input type="url" name="monitoring_link" value="{{ old('monitoring_link') }}" placeholder="https://..." class="block w-full rounded-lg border border-slate-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-slate-500">Paste the shareable HTTP or HTTPS link to your monitoring sheet.</p>
                @error('monitoring_link')
                    <p class="mt-1 text-xs text-red-600">{{ $message }}</p>
                @enderror
            </div>

            <div class="lg:col-span-4 flex items-center justify-end gap-3 pt-2">
                <span class="text-xs text-slate-500">Select a date and link before submitting.</span>
                <button type="submit" class="rounded-xl bg-[#003a78] px-6 py-2.5 text-sm font-semibold text-white shadow-sm transition hover:bg-[#002f61]">Submit Monitoring Link</button>
            </div>
        </form>
    </article>
@endsection
