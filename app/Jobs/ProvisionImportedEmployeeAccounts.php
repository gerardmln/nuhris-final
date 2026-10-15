<?php

namespace App\Jobs;

use App\Models\Employee;
use App\Models\User;
use App\Services\AuditLogService;
use App\Services\EmployeeAccountService;
use App\Services\SupabaseAuthSyncService;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Creates login accounts and emails credentials for employees added through
 * a bulk upload. Dispatched with dispatchAfterResponse() so a 300+ row file
 * does not keep HR waiting on password hashing, Supabase sync and SMTP, and
 * no queue worker is required.
 */
class ProvisionImportedEmployeeAccounts
{
    use Dispatchable;

    /**
     * @param  array<int, int>  $employeeIds
     */
    public function __construct(
        public array $employeeIds,
        public ?int $actorId = null,
        public string $originalFilename = '',
    ) {
    }

    public function handle(EmployeeAccountService $accounts, SupabaseAuthSyncService $supabase, AuditLogService $auditLog): void
    {
        @set_time_limit(0);

        $provisioned = 0;
        $emailed = 0;
        $alreadyHadLogin = 0;
        $failed = [];

        $employees = Employee::query()->whereKey($this->employeeIds)->orderBy('id')->get();
        $existingEmails = User::query()
            ->whereIn('email', $employees->pluck('email'))
            ->pluck('email')
            ->mapWithKeys(fn ($email) => [strtolower($email) => true])
            ->all();

        // Someone who already has a login (e.g. an HR or Admin user) keeps
        // their current password; the employee record links by email.
        $newEmployees = $employees->reject(function (Employee $employee) use ($existingEmails, &$alreadyHadLogin): bool {
            $exists = isset($existingEmails[strtolower($employee->email)]);
            $alreadyHadLogin += (int) $exists;

            return $exists;
        });

        foreach ($newEmployees->chunk(100) as $chunk) {
            $passwords = [];
            $now = now();

            $rows = $chunk->map(function (Employee $employee) use ($accounts, &$passwords, $now): array {
                $passwords[$employee->email] = $accounts->newTemporaryPassword();

                return [
                    'name' => $employee->full_name,
                    'email' => $employee->email,
                    'password' => Hash::make($passwords[$employee->email]),
                    'user_type' => User::TYPE_EMPLOYEE,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            })->values()->all();

            try {
                User::query()->insert($rows);
            } catch (\Throwable $exception) {
                Log::warning('Bulk import: failed to create login accounts', ['error' => $exception->getMessage()]);
                array_push($failed, ...$chunk->pluck('employee_id')->all());

                continue;
            }

            $users = User::query()->whereIn('email', array_keys($passwords))->get()->keyBy('email');

            foreach ($chunk as $employee) {
                $user = $users->get($employee->email);
                $password = $passwords[$employee->email];

                if (! $user) {
                    $failed[] = $employee->employee_id;

                    continue;
                }

                $provisioned++;
                $supabase->syncUser($user, $password);

                if ($accounts->sendCredentials($employee, $password)['sent']) {
                    $emailed++;
                }
            }
        }

        $total = count($this->employeeIds);

        $needed = $total - $alreadyHadLogin;

        $auditLog->record(
            'CREATE',
            'Employees',
            sprintf(
                'Bulk upload %s: created %d of %d login account(s), emailed credentials to %d%s.',
                $this->originalFilename,
                $provisioned,
                $needed,
                $emailed,
                $alreadyHadLogin > 0 ? ", {$alreadyHadLogin} already had a login" : '',
            ),
            $provisioned === $needed && $emailed === $needed ? 'Success' : 'Failed',
            [
                'accounts_created' => $provisioned,
                'emails_sent' => $emailed,
                'already_had_login' => $alreadyHadLogin,
                'account_failures' => $failed,
            ],
            $this->actorId,
        );
    }
}
