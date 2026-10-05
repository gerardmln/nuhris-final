<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TimekeeperCredentialsMail;
use App\Models\Department;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\SupabaseAuthSyncService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RoleManagementController extends Controller
{
    public function index(Request $request): View
    {
        $search = $request->string('search')->toString();
        $roleFilter = $request->string('role')->toString() ?: 'all';
        $statusFilter = $request->string('status')->toString() ?: 'all';
        $departmentId = $request->string('department_id')->toString();

        $users = User::query()
            ->with('employeeProfile')
            ->orderBy('name')
            ->when($search, function ($query, $searchTerm) {
                $query->where(function ($nested) use ($searchTerm) {
                    $nested->where('name', 'like', '%'.$searchTerm.'%')
                        ->orWhere('email', 'like', '%'.$searchTerm.'%');
                });
            })
            ->when($roleFilter !== 'all', fn ($query) => $query->where('user_type', $roleFilter))
            ->when($statusFilter === 'active', fn ($query) => $query->whereNotNull('email_verified_at'))
            ->when($statusFilter === 'inactive', fn ($query) => $query->whereNull('email_verified_at'))
            ->when(filled($departmentId), function ($query) use ($departmentId) {
                if ($departmentId === 'asp') {
                    $query->whereHas('employeeProfile', fn ($employeeQuery) => $employeeQuery->where('employment_type', 'Admin Support Personnel'));

                    return;
                }

                $query->whereHas('employeeProfile', fn ($employeeQuery) => $employeeQuery->where('department_id', $departmentId));
            })
            ->get()
            ->map(function (User $user) {
                return [
                    'id' => $user->id,
                    'name' => $user->name,
                    'email' => $user->email,
                    'role' => $this->roleLabel($user->user_type),
                    'user_type' => $user->user_type,
                    'department' => $user->employeeProfile?->department?->name ?? 'N/A',
                    'status' => $user->email_verified_at ? 'Active' : 'Inactive',
                    'created_at' => $user->created_at?->format('M d, Y'),
                ];
            });

        $roleDistribution = [
            'Admin' => User::query()->where('user_type', User::TYPE_ADMIN)->count(),
            'HR' => User::query()->where('user_type', User::TYPE_HR)->count(),
            'Employee' => User::query()->where('user_type', User::TYPE_EMPLOYEE)->count(),
        ];

        return view('admin.role-management.index', [
            'users' => $users,
            'roleDistribution' => $roleDistribution,
            'roles' => [
                ['value' => User::TYPE_ADMIN, 'label' => 'Admin'],
                ['value' => User::TYPE_HR, 'label' => 'HR'],
                ['value' => User::TYPE_EMPLOYEE, 'label' => 'Employee'],
            ],
            'departments' => Department::query()->orderBy('name')->get(),
            'filters' => [
                'search' => $search,
                'role' => $roleFilter,
                'status' => $statusFilter,
                'department_id' => $departmentId,
            ],
        ]);
    }

    public function updateRole(Request $request, User $user): RedirectResponse
    {
        $validated = $request->validate([
            'user_type' => 'required|integer|in:'.User::TYPE_ADMIN.','.User::TYPE_HR.','.User::TYPE_EMPLOYEE,
        ]);

        // Prevent removing all admins
        if ($validated['user_type'] !== User::TYPE_ADMIN && $user->user_type === User::TYPE_ADMIN) {
            $adminCount = User::query()->where('user_type', User::TYPE_ADMIN)->count();
            if ($adminCount <= 1) {
                app(AuditLogService::class)->record(
                    'UPDATE',
                    'Role Management',
                    'Blocked role change for '.$user->email.' because they are the last Admin.',
                    'Failed',
                    ['user_id' => $user->id, 'email' => $user->email]
                );

                return redirect()->back()
                    ->with('error', 'Cannot remove the last Admin user. At least one Admin must exist.');
            }
        }

        $oldRole = $this->roleLabel($user->user_type);
        $newRole = $this->roleLabel($validated['user_type']);

        $user->update($validated);

        app(AuditLogService::class)->record(
            'UPDATE',
            'Role Management',
            sprintf('Updated %s role from %s to %s.', $user->email, $oldRole, $newRole),
            'Success',
            ['user_id' => $user->id, 'email' => $user->email, 'from' => $oldRole, 'to' => $newRole]
        );

        return redirect()->route('admin.roles.index')
            ->with('success', "{$user->name}'s role has been changed from {$oldRole} to {$newRole}.");
    }

    public function addTimekeeper(Request $request): RedirectResponse
    {
        $validated = $request->validate([
            'email' => ['required', 'email', 'max:255', 'unique:users,email'],
        ]);

        $email = strtolower(trim($validated['email']));
        $temporaryPassword = Str::upper(Str::random(4)).'-'.random_int(1000, 9999);
        $name = Str::of(Str::before($email, '@'))
            ->replace(['.', '_', '-'], ' ')
            ->title()
            ->toString() ?: 'Timekeeper';

        $user = DB::transaction(function () use ($email, $name, $temporaryPassword) {
            $user = User::create([
                'name' => $name,
                'email' => $email,
                'password' => Hash::make($temporaryPassword),
                'user_type' => User::TYPE_HR,
            ]);

            app(SupabaseAuthSyncService::class)->syncUser($user, $temporaryPassword);

            return $user;
        });

        $emailStatus = ['sent' => false, 'message' => null];

        try {
            Mail::to($user->email)->send(new TimekeeperCredentialsMail($user, $temporaryPassword));
            $emailStatus['sent'] = true;
        } catch (\Throwable $exception) {
            Log::warning('Admin: Failed to send timekeeper credentials email', [
                'user_id' => $user->id,
                'email' => $user->email,
                'error' => $exception->getMessage(),
            ]);
            $emailStatus['message'] = 'Email delivery failed because SMTP authentication is unavailable right now. Please share the credentials manually.';
        }

        app(AuditLogService::class)->record(
            'CREATE',
            'Role Management',
            'Created HR Timekeeper account for '.$user->email.'.',
            $emailStatus['sent'] ? 'Success' : 'Failed',
            ['user_id' => $user->id, 'email' => $user->email, 'email_sent' => $emailStatus['sent']]
        );

        return redirect()->route('admin.roles.index')
            ->with($emailStatus['sent'] ? 'success' : 'error', $emailStatus['sent']
                ? "Timekeeper account created and credentials sent to {$user->email}."
                : "Timekeeper account created, but credentials could not be emailed to {$user->email}.")
            ->with('credential_notice', ['email' => $user->email, 'email_status' => $emailStatus]);
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->user_type === User::TYPE_ADMIN) {
            return redirect()->route('admin.roles.index')
                ->with('error', 'Admin users cannot be deleted.');
        }

        if ($user->user_type === User::TYPE_EMPLOYEE) {
            return redirect()->route('admin.roles.index')
                ->with('error', 'To delete an employee, go to the Employees module.');
        }

        $email = $user->email;
        $userId = $user->id;

        app(SupabaseAuthSyncService::class)->deleteUser($user);
        $user->delete();

        app(AuditLogService::class)->record(
            'DELETE',
            'Role Management',
            'Deleted HR Timekeeper account for '.$email.'.',
            'Success',
            ['user_id' => $userId, 'email' => $email]
        );

        return redirect()->route('admin.roles.index')
            ->with('success', "Timekeeper account {$email} has been deleted.");
    }

    private function roleLabel(int $userType): string
    {
        return match ($userType) {
            User::TYPE_ADMIN => 'Admin',
            User::TYPE_HR => 'HR',
            User::TYPE_EMPLOYEE => 'Employee',
            default => 'Unknown',
        };
    }
}
