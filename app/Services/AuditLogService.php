<?php

namespace App\Services;

use App\Models\AdminAuditLog;
use App\Models\User;
use Illuminate\Support\Facades\Log;
use Throwable;

class AuditLogService
{
    private const SENSITIVE_KEYS = [
        'password',
        'password_confirmation',
        'current_password',
        'token',
        'remember_token',
        'key_value',
        'api_key',
        'secret',
        'file',
        'biometrics_file',
        'leaves_file',
    ];

    public function record(
        string $action,
        string $module,
        string $description,
        string $status = 'Success',
        array $metadata = [],
        ?int $userId = null,
    ): void {
        try {
            $metadata = $this->safeMetadata($metadata);
            $ip = request()?->ip();

            if (filled($ip)) {
                $metadata['ip'] = $ip;
            }

            AdminAuditLog::query()->create([
                'user_id' => $userId ?? auth()->id(),
                'action' => strtoupper($action),
                'module' => $module,
                'description' => $description,
                'status' => $status,
                'metadata' => $metadata === [] ? null : $metadata,
            ]);
        } catch (Throwable $exception) {
            Log::warning('Failed to write admin audit log', [
                'action' => $action,
                'module' => $module,
                'error' => $exception->getMessage(),
            ]);
        }
    }

    public function roleLabel(?int $userType): string
    {
        return match ($userType) {
            User::TYPE_ADMIN => 'Admin',
            User::TYPE_HR => 'HR Personnel',
            User::TYPE_EMPLOYEE => 'Employee',
            default => 'System',
        };
    }

    /**
     * @param  array<string, mixed>  $metadata
     * @return array<string, mixed>
     */
    private function safeMetadata(array $metadata): array
    {
        $clean = [];

        foreach ($metadata as $key => $value) {
            if (in_array(strtolower((string) $key), self::SENSITIVE_KEYS, true)) {
                continue;
            }

            $clean[$key] = is_array($value) ? $this->safeMetadata($value) : $value;
        }

        return $clean;
    }
}
