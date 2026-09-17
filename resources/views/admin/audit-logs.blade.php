@extends('admin.layout')

@section('title', 'Audit Logs')
@section('page_title', 'Audit Logs')

@section('content')
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 xl:grid-cols-4">
        <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">Total Logs Today</p><p class="text-4xl font-extrabold">{{ $stats['total'] }}</p></article>
        <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">Successful Actions</p><p class="text-4xl font-extrabold">{{ $stats['success'] }}</p></article>
        <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">Failed Actions</p><p class="text-4xl font-extrabold">{{ $stats['failed'] }}</p></article>
        <article class="rounded-xl border border-slate-300 bg-white p-4 shadow-sm"><p class="text-xs text-slate-500">Users Active Today</p><p class="text-4xl font-extrabold">{{ $stats['active_users'] }}</p></article>
    </div>

    <form method="GET" action="{{ route('admin.integration.audit') }}" class="flex flex-wrap items-center gap-2">
        <input name="search" value="{{ $filters['search'] }}" class="w-full rounded-lg border border-slate-300 px-3 py-2 text-sm md:w-96" placeholder="Search by user or description...">
        <select name="action" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">All Actions</option>
            @foreach (['CREATE' => 'Create', 'UPDATE' => 'Update', 'DELETE' => 'Delete', 'LOGIN' => 'Login', 'LOGOUT' => 'Logout', 'APPROVE' => 'Approve', 'DECLINE' => 'Decline', 'EXPORT' => 'Export', 'RESET' => 'Reset', 'VIEW' => 'View'] as $value => $label)
                <option value="{{ $value }}" @selected($filters['action'] === $value)>{{ $label }}</option>
            @endforeach
        </select>
        <select name="module" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">All Modules</option>
            @foreach ($modules as $module)
                <option value="{{ $module }}" @selected($filters['module'] === $module)>{{ $module }}</option>
            @endforeach
        </select>
        <select name="role" class="rounded-lg border border-slate-300 px-3 py-2 text-sm">
            <option value="">All Roles</option>
            <option value="admin" @selected($filters['role'] === 'admin')>Admin</option>
            <option value="hr" @selected($filters['role'] === 'hr')>HR Personnel</option>
            <option value="employee" @selected($filters['role'] === 'employee')>Employee</option>
            <option value="system" @selected($filters['role'] === 'system')>System</option>
        </select>
        <button type="submit" class="rounded-lg bg-[#24358a] px-4 py-2 text-sm font-semibold text-white">Filter</button>
        @if ($filters['search'] !== '' || $filters['action'] !== '' || $filters['module'] !== '' || $filters['role'] !== '')
            <a href="{{ route('admin.integration.audit') }}" class="rounded-lg border border-slate-300 px-4 py-2 text-sm font-semibold text-slate-700">Clear</a>
        @endif
    </form>

    <article class="overflow-x-auto rounded-xl border border-slate-300 bg-white p-4 shadow-sm">
        <div class="flex flex-wrap items-end justify-between gap-2">
            <h3 class="text-2xl font-bold text-[#24358a]">Audit Log Entries</h3>
            <p class="text-xs text-slate-500">{{ $logs->total() }} matching {{ \Illuminate\Support\Str::plural('entry', $logs->total()) }}</p>
        </div>
        <table class="mt-3 min-w-full text-left text-xs">
            <thead class="bg-slate-100">
                <tr>
                    <th class="px-2 py-2">Timestamp</th>
                    <th class="px-2 py-2">User</th>
                    <th class="px-2 py-2">Role</th>
                    <th class="px-2 py-2">Action</th>
                    <th class="px-2 py-2">Module</th>
                    <th class="px-2 py-2">Description</th>
                    <th class="px-2 py-2">Status</th>
                    <th class="px-2 py-2">IP</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse ($logs as $log)
                    <tr>
                        <td class="px-2 py-2 whitespace-nowrap">{{ $log->created_at?->format('Y-m-d H:i:s') }}</td>
                        <td class="px-2 py-2">{{ $log->user?->name ?? 'System' }}</td>
                        <td class="px-2 py-2">{{ $log->roleLabel() }}</td>
                        <td class="px-2 py-2">{{ strtoupper($log->action) }}</td>
                        <td class="px-2 py-2">{{ $log->module }}</td>
                        <td class="px-2 py-2">{{ $log->description }}</td>
                        <td class="px-2 py-2 {{ $log->status === 'Success' ? 'text-emerald-700' : 'text-red-700' }}">{{ $log->status }}</td>
                        <td class="px-2 py-2 whitespace-nowrap">{{ data_get($log->metadata, 'ip', '—') }}</td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-2 py-4 text-center text-slate-500">No logs found.</td></tr>
                @endforelse
            </tbody>
        </table>
        <div class="mt-4">{{ $logs->links() }}</div>
    </article>
@endsection
