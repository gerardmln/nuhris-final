@extends('admin.layout')

@section('title', 'Role Management')
@section('hide_page_heading', '1')

@php
    $pageTitle = 'Role Management';
    $pageHeading = 'Role Management';
@endphp

@section('content')
    <!-- Role Distribution -->
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-3">
        <article class="rounded-xl border border-l-4 border-slate-300 border-l-blue-500 bg-white p-4 shadow-sm">
            <p class="text-xs text-slate-500">Admins</p>
            <p class="text-4xl font-extrabold">{{ $roleDistribution['Admin'] }}</p>
        </article>
        <article class="rounded-xl border border-l-4 border-slate-300 border-l-green-500 bg-white p-4 shadow-sm">
            <p class="text-xs text-slate-500">HR Personnel</p>
            <p class="text-4xl font-extrabold">{{ $roleDistribution['HR'] }}</p>
        </article>
        <article class="rounded-xl border border-l-4 border-slate-300 border-l-purple-500 bg-white p-4 shadow-sm">
            <p class="text-xs text-slate-500">Employees</p>
            <p class="text-4xl font-extrabold">{{ $roleDistribution['Employee'] }}</p>
        </article>
    </div>

    <form method="GET" action="{{ route('admin.roles.index') }}" class="grid grid-cols-1 gap-2 sm:grid-cols-2 lg:grid-cols-[minmax(0,2fr)_repeat(2,minmax(0,1fr))_minmax(0,1.5fr)]">
        <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search by name or email" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none">
        <select name="role" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none">
            <option value="all" @selected(($filters['role'] ?? 'all') === 'all')>All Roles</option>
            @foreach($roles as $role)
                <option value="{{ $role['value'] }}" @selected((string) ($filters['role'] ?? '') === (string) $role['value'])>{{ $role['short'] }}</option>
            @endforeach
        </select>
        <select name="status" onchange="this.form.submit()" class="rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none">
            <option value="all" @selected(($filters['status'] ?? 'all') === 'all')>All Statuses</option>
            <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
            <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
        </select>
        <select name="department_id" onchange="this.form.submit()" class="min-w-0 rounded-lg border border-slate-300 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none">
            <option value="all" @selected(($filters['department_id'] ?? 'all') === 'all')>All Departments</option>
            <option value="asp" @selected(($filters['department_id'] ?? '') === 'asp')>Admin Support Personnel</option>
            @foreach ($departments as $department)
                <option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>
            @endforeach
        </select>
    </form>

    <!-- Users List -->
    <div class="overflow-x-auto rounded-xl border border-slate-300 bg-white shadow-sm">
        <table class="w-full min-w-[760px] text-sm">
            <thead class="border-b border-slate-200 bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-600">
                <tr>
                    <th class="px-4 py-3">User</th>
                    <th class="px-4 py-3">Role</th>
                    <th class="px-4 py-3">Department</th>
                    <th class="px-4 py-3">Status</th>
                    <th class="px-4 py-3">Change Role</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @forelse($users as $user)
                    <tr class="align-middle hover:bg-slate-50">
                        <td class="px-4 py-3">
                            <p class="font-medium text-slate-900">{{ $user['name'] }}</p>
                            <p class="text-xs text-slate-500">{{ $user['email'] }}</p>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <span class="inline-flex items-center rounded-full px-2.5 py-0.5 text-xs font-semibold
                                @if($user['user_type'] === 1) bg-red-100 text-red-800
                                @elseif($user['user_type'] === 2) bg-blue-100 text-blue-800
                                @else bg-slate-100 text-slate-800
                                @endif">
                                {{ $user['role'] }}
                            </span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">
                            <span title="{{ $user['department'] }}">{{ $user['department_code'] }}</span>
                        </td>
                        <td class="whitespace-nowrap px-4 py-3 text-slate-600">{{ $user['status'] }}</td>
                        <td class="whitespace-nowrap px-4 py-3">
                            <form action="{{ route('admin.roles.update', $user['id']) }}" method="POST" class="flex items-center gap-2">
                                @csrf
                                @method('PUT')
                                <select name="user_type" class="w-40 rounded-md border border-slate-300 px-2 py-1.5 text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                    @foreach($roles as $role)
                                        <option value="{{ $role['value'] }}" @selected($user['user_type'] === $role['value'])>{{ $role['label'] }}</option>
                                    @endforeach
                                </select>
                                <button type="submit" class="rounded-md bg-emerald-600 px-3 py-1.5 text-sm font-medium text-white hover:bg-emerald-700">Save</button>
                            </form>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="5" class="px-4 py-8 text-center text-slate-500">No users match the current filters.</td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    <div class="rounded-lg border border-blue-200 bg-blue-50 p-4">
        <p class="text-sm text-blue-900"><strong>Note:</strong> Admin can open the Admin, HR and Employee portals; HR can open the HR and Employee portals; Employee can open only the Employee portal. At least one Admin user must always exist.</p>
    </div>
@endsection
