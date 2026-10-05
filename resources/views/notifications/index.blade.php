@extends(auth()->user()?->isAdmin() ? 'admin.layout' : 'hr.layout')

@section('title', 'Notifications')
@section('page_title', 'Notifications')

@section('content')
    @php
        $routePrefix = auth()->user()?->isAdmin() ? 'admin.notifications' : 'notifications';
    @endphp

    <div class="flex flex-col gap-3 sm:flex-row sm:items-start sm:justify-between">
        @unless (auth()->user()?->isAdmin())
            <div>
                <h2 class="text-3xl font-bold text-[#1f2b5d]">Notifications</h2>
                <p class="text-sm text-slate-500">Updates related to employee submissions and profile changes.</p>
            </div>
        @endunless
        <div class="flex flex-wrap gap-2">
            @if ($notifications->where('is_read', false)->isNotEmpty())
                <form method="POST" action="{{ route($routePrefix.'.read-all') }}">
                    @csrf
                    <button type="submit" class="rounded-lg border border-blue-300 bg-white px-4 py-2 text-sm font-semibold text-blue-700 hover:bg-blue-50">Read All</button>
                </form>
            @endif
            <form method="POST" action="{{ route($routePrefix.'.clear-all') }}" onsubmit="return confirm('Clear all notifications? This will remove them from your inbox.');">
                @csrf
                @method('DELETE')
                <button type="submit" class="rounded-lg border border-slate-300 bg-white px-4 py-2 text-sm font-semibold text-slate-700 hover:bg-slate-50">Clear All</button>
            </form>
        </div>
    </div>

    <article class="rounded-xl border border-slate-300 bg-white shadow-sm">
        @if ($notifications->isEmpty())
            <div class="flex min-h-[320px] flex-col items-center justify-center px-6 py-10 text-center">
                <svg class="h-14 w-14 text-slate-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.7" aria-hidden="true">
                    <path d="M15 17h5l-1.4-1.4A2 2 0 0 1 18 14.2V11a6 6 0 1 0-12 0v3.2a2 2 0 0 1-.6 1.4L4 17h5"></path>
                    <path d="M10 20a2 2 0 0 0 4 0"></path>
                </svg>
                <h3 class="mt-4 text-4xl font-bold text-slate-400">No Notifications Yet</h3>
            </div>
        @else
            <div class="divide-y divide-slate-200">
                @foreach ($notifications as $notification)
                    @php
                        $announcement = $notification->announcement;
                        $priorityLabel = $announcement?->priority_label ?? 'Medium';
                        $priorityBadgeClass = $announcement?->priority_badge_class ?? 'bg-blue-100 text-blue-700';
                    @endphp
                    <a href="{{ route($routePrefix.'.open', $notification) }}" class="block px-6 py-4 transition hover:bg-slate-50 {{ $notification->is_read ? 'bg-slate-100' : 'bg-white' }}">
                        <div class="flex items-start justify-between gap-3">
                            <p class="text-sm font-semibold {{ $notification->is_read ? 'text-slate-500' : 'text-slate-900' }}">{{ $notification->title_text }}</p>
                            <div class="flex items-center gap-2">
                                <span class="rounded-full px-3 py-1 text-xs font-semibold {{ $priorityBadgeClass }}">{{ $priorityLabel }}</span>
                                @unless ($notification->is_read)
                                    <span class="mt-0.5 inline-flex shrink-0 items-center gap-1 rounded-full bg-blue-100 px-2 py-0.5 text-[10px] font-semibold uppercase tracking-wide text-blue-700">
                                        <span class="h-2 w-2 rounded-full bg-blue-600"></span>
                                        Unread
                                    </span>
                                @endunless
                            </div>
                        </div>
                        <p class="mt-1 text-sm {{ $notification->is_read ? 'text-slate-500' : 'text-slate-700' }}">{{ $notification->content_text }}</p>
                        <p class="mt-2 text-xs text-slate-400">{{ $notification->created_at->format('M d, Y h:i A') }}</p>
                    </a>
                @endforeach
            </div>
        @endif
    </article>
@endsection
