@extends('employee.layout')

@section('title', 'Announcements')
@section('page_title', 'Announcements')

@section('content')
    <div>
        <h2 class="text-3xl font-bold text-[#1f2b5d]">HR Announcements</h2>
        <p class="text-sm text-slate-500">Updates published by HR for employees.</p>
    </div>

    <div class="space-y-4">
        @forelse ($announcements as $notification)
            @php($announcement = $notification->announcement)
            <a href="{{ route('employee.announcements.open', $notification) }}" class="block rounded-2xl border border-slate-300 bg-white px-6 py-5 shadow-sm transition hover:border-blue-300 hover:bg-blue-50">
                <div class="flex items-start justify-between gap-3">
                    <div>
                        <h3 class="text-2xl font-bold text-slate-900">{{ $announcement->title }}</h3>
                        <p class="mt-1 text-xs text-slate-400">{{ $announcement->published_at?->format('M d, Y h:i A') }}</p>
                    </div>
                    <span class="rounded-full px-3 py-1 text-sm font-semibold {{ $announcement->priority_badge_class }}">{{ $announcement->priority_label }}</span>
                </div>
                <p class="mt-4 text-sm text-slate-700">{{ $announcement->content }}</p>
            </a>
        @empty
            <p class="py-24 text-center text-2xl text-slate-400">No announcements found</p>
        @endforelse
    </div>
@endsection
