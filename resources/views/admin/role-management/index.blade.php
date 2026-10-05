@extends('admin.layout')

@section('title', 'Role Management')

@section('content')
<div class="p-8">
    <div class="mb-8 flex flex-col gap-4 sm:flex-row sm:items-center sm:justify-between">
        <h1 class="text-4xl font-bold text-slate-900">Role Management</h1>
        <button type="button" id="open-timekeeper-modal" class="rounded-lg bg-[#0a3f79] px-4 py-2.5 text-sm font-semibold text-white shadow hover:bg-[#083266]">
            Add Timekeeper
        </button>
    </div>

    @if (session('success') || session('error'))
        <div class="mb-6 rounded-lg border px-4 py-3 text-sm {{ session('success') ? 'border-emerald-200 bg-emerald-50 text-emerald-800' : 'border-red-200 bg-red-50 text-red-800' }}">
            {{ session('success') ?? session('error') }}
            @if (session('credential_notice.email_status.message'))
                <div class="mt-1">{{ session('credential_notice.email_status.message') }}</div>
            @endif
        </div>
    @endif

    <!-- Role Distribution -->
    <div class="grid grid-cols-1 md:grid-cols-3 gap-6 mb-8">
        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-blue-500">
            <p class="text-slate-600 text-sm font-medium mb-2">Admins</p>
            <p class="text-4xl font-bold text-slate-900">{{ $roleDistribution['Admin'] }}</p>
        </div>

        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-green-500">
            <p class="text-slate-600 text-sm font-medium mb-2">HR Personnel</p>
            <p class="text-4xl font-bold text-slate-900">{{ $roleDistribution['HR'] }}</p>
        </div>

        <div class="bg-white rounded-lg shadow p-6 border-l-4 border-purple-500">
            <p class="text-slate-600 text-sm font-medium mb-2">Employees</p>
            <p class="text-4xl font-bold text-slate-900">{{ $roleDistribution['Employee'] }}</p>
        </div>
    </div>

    <div class="mb-6 rounded-lg border border-slate-300 bg-white p-4 shadow-sm">
        <form method="GET" action="{{ route('admin.roles.index') }}" class="grid grid-cols-1 gap-3 md:grid-cols-8">
            <input type="text" name="search" value="{{ $filters['search'] ?? '' }}" placeholder="Search by name or email" class="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none md:col-span-3">
            <select name="role" onchange="this.form.submit()" class="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none">
                <option value="all" @selected(($filters['role'] ?? 'all') === 'all')>All Roles</option>
                @foreach($roles as $role)
                    <option value="{{ $role['value'] }}" @selected((string) ($filters['role'] ?? '') === (string) $role['value'])>{{ $role['label'] }}</option>
                @endforeach
            </select>
            <select name="status" onchange="this.form.submit()" class="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none">
                <option value="all" @selected(($filters['status'] ?? 'all') === 'all')>All Statuses</option>
                <option value="active" @selected(($filters['status'] ?? '') === 'active')>Active</option>
                <option value="inactive" @selected(($filters['status'] ?? '') === 'inactive')>Inactive</option>
            </select>
            <select name="department_id" onchange="this.form.submit()" class="rounded-md border border-slate-300 px-3 py-2 text-sm focus:border-blue-400 focus:outline-none md:col-span-2">
                <option value="all" @selected(($filters['department_id'] ?? 'all') === 'all')>All Departments</option>
                <option value="asp" @selected(($filters['department_id'] ?? '') === 'asp')>Admin Support Personnel</option>
                @foreach ($departments as $department)
                    <option value="{{ $department->id }}" @selected(($filters['department_id'] ?? '') == $department->id)>{{ $department->name }}</option>
                @endforeach
            </select>
        </form>
    </div>

    <!-- Users List -->
    <div class="bg-white rounded-lg shadow overflow-hidden">
        <table class="w-full">
            <thead class="bg-slate-50 border-b border-slate-200">
                <tr>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900">Name</th>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900">Email</th>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900">Current Role</th>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900">Department</th>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900">Status</th>
                    <th class="px-6 py-3 text-left text-sm font-semibold text-slate-900">Change Role</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-slate-200">
                @foreach($users as $user)
                    <tr class="hover:bg-slate-50 transition">
                        <td class="px-6 py-4 text-sm font-medium text-slate-900">{{ $user['name'] }}</td>
                        <td class="px-6 py-4 text-sm text-slate-600">{{ $user['email'] }}</td>
                        <td class="px-6 py-4 text-sm">
                            <span class="inline-flex items-center px-3 py-1 rounded-full text-xs font-semibold
                                @if($user['user_type'] === 1) bg-red-100 text-red-800
                                @elseif($user['user_type'] === 2) bg-blue-100 text-blue-800
                                @else bg-slate-100 text-slate-800
                                @endif">
                                {{ $user['role'] }}
                            </span>
                        </td>
                        <td class="px-6 py-4 text-sm text-slate-600">{{ $user['department'] }}</td>
                        <td class="px-6 py-4 text-sm text-slate-600">{{ $user['status'] }}</td>
                            <td class="px-6 py-4 text-sm">
                                <form action="{{ route('admin.roles.update', $user['id']) }}" method="POST" class="flex gap-2">
                                    @csrf
                                    @method('PUT')
                                    <select name="user_type" class="px-3 py-1 border border-slate-300 rounded text-sm focus:outline-none focus:ring-2 focus:ring-blue-500">
                                        @foreach($roles as $role)
                                            <option value="{{ $role['value'] }}" @selected($user['user_type'] === $role['value'])>{{ $role['label'] }}</option>
                                        @endforeach
                                    </select>
                                    <button type="submit" class="bg-emerald-600 hover:bg-emerald-700 text-white px-3 py-1 rounded text-sm font-medium">Save</button>
                                </form>
                            </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>

    <div class="mt-8 p-4 bg-blue-50 border border-blue-200 rounded-lg">
        <p class="text-sm text-blue-900"><strong>Note:</strong> At least one Admin user must always exist. The system will prevent you from removing the last Admin.</p>
    </div>
</div>

<div id="timekeeper-modal" class="fixed inset-0 z-50 {{ $errors->has('email') ? 'flex' : 'hidden' }} items-center justify-center bg-slate-900/60 px-4" aria-hidden="true">
    <div class="w-full max-w-md rounded-2xl bg-white p-6 shadow-2xl">
        <div class="mb-5">
            <h2 class="text-xl font-bold text-slate-900">Add Timekeeper</h2>
            <p class="mt-1 text-sm text-slate-500">Create an HR user without creating an employee record. Credentials will be sent to the submitted email.</p>
        </div>
        <form method="POST" action="{{ route('admin.roles.timekeepers.store') }}">
            @csrf
            <label for="timekeeper-email" class="mb-2 block text-sm font-semibold text-slate-700">Email account</label>
            <input id="timekeeper-email" type="email" name="email" value="{{ old('email') }}" required maxlength="255" placeholder="timekeeper@gmail.com" class="w-full rounded-xl border border-slate-300 px-3 py-2.5 text-sm focus:border-[#0a3f79] focus:outline-none focus:ring-2 focus:ring-[#0a3f79]/20">
            @error('email')
                <p class="mt-2 text-sm text-red-600">{{ $message }}</p>
            @enderror
            <div class="mt-6 flex justify-end gap-3">
                <button type="button" id="close-timekeeper-modal" class="rounded-xl border border-slate-300 px-4 py-2.5 text-sm font-semibold text-slate-700 hover:bg-slate-50">Cancel</button>
                <button type="submit" class="rounded-xl bg-[#0a3f79] px-4 py-2.5 text-sm font-semibold text-white hover:bg-[#083266]">Create and send credentials</button>
            </div>
        </form>
    </div>
</div>

<script>
    const timekeeperModal = document.getElementById('timekeeper-modal');
    document.getElementById('open-timekeeper-modal').addEventListener('click', () => {
        timekeeperModal.classList.remove('hidden');
        timekeeperModal.classList.add('flex');
        document.getElementById('timekeeper-email').focus();
    });
    document.getElementById('close-timekeeper-modal').addEventListener('click', () => {
        timekeeperModal.classList.add('hidden');
        timekeeperModal.classList.remove('flex');
    });
</script>
@endsection
