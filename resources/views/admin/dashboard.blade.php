@extends('admin.layout')

@section('title', 'Admin Dashboard')
@section('page_title', 'Dashboard')

@section('content')
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-5">
        <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium text-slate-500">Total Employees</p>
            <p class="mt-1 text-4xl font-extrabold">{{ $stats['total_employees'] }}</p>
        </article>
        <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium text-slate-500">Compliance Rate</p>
            <p class="mt-1 text-4xl font-extrabold">{{ $stats['compliance_rate'] }}%</p>
        </article>
        <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium text-slate-500">Expiring PRC</p>
            <p class="mt-1 text-4xl font-extrabold">{{ $stats['expiring_prc'] }}</p>
        </article>
        <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
            <p class="text-xs font-medium text-slate-500">Pending Verifications</p>
            <p class="mt-1 text-4xl font-extrabold">{{ $stats['pending_verifications'] }}</p>
        </article>
    </div>

    <div class="grid grid-cols-1 gap-4 xl:grid-cols-3">
        <div class="space-y-4 xl:col-span-2">
            <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
                <h2 class="mb-3 text-2xl font-bold text-slate-800">Action Required</h2>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-2">
                    @foreach ($actionRequiredCards as $card)
                        @php
                            $toneStyles = match ($card['tone']) {
                                'amber' => 'border border-amber-200 bg-amber-50 hover:bg-amber-100 text-amber-900',
                                'blue' => 'border border-blue-200 bg-blue-50 hover:bg-blue-100 text-blue-900',
                                'emerald' => 'border border-emerald-200 bg-emerald-50 hover:bg-emerald-100 text-emerald-900',
                                default => 'border border-slate-200 bg-slate-50 hover:bg-slate-100 text-slate-900',
                            };

                            $countToneStyles = match ($card['tone']) {
                                'amber' => 'text-amber-900',
                                'blue' => 'text-blue-900',
                                'emerald' => 'text-emerald-900',
                                default => 'text-slate-900',
                            };

                            $descriptionToneStyles = match ($card['tone']) {
                                'amber' => 'text-amber-700',
                                'blue' => 'text-blue-700',
                                'emerald' => 'text-emerald-700',
                                default => 'text-slate-600',
                            };
                        @endphp

                        <a href="{{ $card['href'] }}" class="flex items-center justify-between rounded-xl px-4 py-3 shadow-sm transition {{ $toneStyles }}">
                            <div>
                                <p class="font-semibold {{ $countToneStyles }}">{{ $card['count'] }} {{ $card['title'] }}</p>
                                <p class="text-xs {{ $descriptionToneStyles }}">{{ $card['count'] > 0 ? $card['description'] : $card['empty_label'] }}</p>
                            </div>
                            <span class="text-xl font-light text-slate-400">&gt;</span>
                        </a>
                    @endforeach
                </div>
            </article>

            <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
                <h2 class="mb-2 text-2xl font-bold text-slate-800">Records Overview</h2>
                <p class="mb-3 text-sm text-slate-500">Current administrative records and activity.</p>

                <div class="grid grid-cols-1 gap-3 md:grid-cols-3">
                    @foreach ($recordsOverview as $record)
                        <div class="rounded-xl border border-slate-200 bg-slate-50 p-4">
                            <p class="text-sm font-semibold text-slate-700">{{ $record['label'] }}</p>
                            <p class="mt-2 text-3xl font-extrabold">{{ $record['value'] }}</p>
                        </div>
                    @endforeach
                </div>
            </article>
        </div>

        <div class="space-y-4">
            <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h2 class="text-2xl font-bold text-slate-800">Calendar</h2>
                        <p class="text-sm text-slate-500">Browse the academic calendar by month.</p>
                    </div>
                    <a href="{{ route('admin.academic-calendar.index') }}" class="rounded-xl border border-sky-200 bg-sky-50 px-3 py-2 text-center text-sm font-semibold text-sky-700 hover:bg-sky-100">Open Calendar</a>
                </div>

                <div class="mt-4 rounded-xl border border-slate-200 bg-slate-50 p-4">
                    <p class="text-xs font-semibold uppercase tracking-wide text-slate-500">Upcoming academic dates</p>
                    <div class="mt-3 space-y-2">
                        @forelse ($academicCalendarEntries as $entry)
                            <div class="rounded-lg border border-blue-200 bg-blue-50 px-3 py-2">
                                <p class="text-sm font-semibold text-slate-800">{{ $entry->title }}</p>
                                <p class="text-xs text-slate-500">{{ $entry->event_date->format('M d, Y') }} · {{ $entry->type_label }}</p>
                            </div>
                        @empty
                            <p class="text-sm text-slate-500">No upcoming academic calendar entries yet.</p>
                        @endforelse
                    </div>
                </div>
            </article>

        </div>
    </div>
@endsection