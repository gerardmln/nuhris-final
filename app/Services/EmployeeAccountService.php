<?php

namespace App\Services;

use App\Mail\EmployeeCredentialsMail;
use App\Models\Employee;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

class EmployeeAccountService
{
    /**
     * Create or refresh the linked User login account for an Employee.
     *
     * @return string Plain-text temporary password (shown once to HR).
     */
    public function provision(Employee $employee, bool $forceReset = false): string
    {
        $tempPassword = $this->newTemporaryPassword();

        DB::transaction(function () use ($employee, $tempPassword, $forceReset) {
            $user = User::query()->where('email', $employee->email)->first();

            if (! $user) {
                $this->createAccount($employee, $tempPassword);

                return;
            }

            if ($forceReset) {
                $user->forceFill([
                    'name' => $employee->full_name,
                    'password' => Hash::make($tempPassword),
                ])->save();

                if (! app(SupabaseAuthSyncService::class)->updateUserPassword($user, $tempPassword)) {
                    throw new \RuntimeException('Failed to synchronize password with authentication service. Employee will not be able to log in.');
                }
            }
        });

        return $tempPassword;
    }

    /**
     * Create a new employee login. Callers must know no user with this email exists.
     */
    public function createAccount(Employee $employee, string $tempPassword): User
    {
        $user = User::create([
            'name' => $employee->full_name,
            'email' => $employee->email,
            'password' => Hash::make($tempPassword),
            'user_type' => User::TYPE_EMPLOYEE,
        ]);

        app(SupabaseAuthSyncService::class)->syncUser($user, $tempPassword);

        return $user;
    }

    public function newTemporaryPassword(): string
    {
        return Str::upper(Str::random(4)).'-'.random_int(1000, 9999);
    }

    /**
     * Send the credentials email via the configured mailer. Delivery failures
     * are logged and swallowed so HR can still share the password manually.
     *
     * @return array{sent:bool,message:?string}
     */
    public function sendCredentials(Employee $employee, string $tempPassword, bool $isResend = false): array
    {
        try {
            Mail::to($employee->email)
                ->send(new EmployeeCredentialsMail($employee, $tempPassword, $isResend));

            return ['sent' => true, 'message' => null];
        } catch (\Throwable $exception) {
            Log::warning('Failed to send employee credentials email', [
                'employee_id' => $employee->id,
                'email' => $employee->email,
                'error' => $exception->getMessage(),
            ]);

            return [
                'sent' => false,
                'message' => 'Email delivery failed because SMTP authentication is unavailable right now. Please share the credentials manually.',
            ];
        }
    }
}
