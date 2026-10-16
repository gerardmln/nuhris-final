<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Employee extends Model
{
    use HasFactory, SoftDeletes;

    protected static function booted(): void
    {
        static::creating(function (Employee $employee): void {
            if (filled($employee->employee_id)) {
                return;
            }

            $employee->employee_id = static::generateEmployeeId();
        });
    }

    public const TYPE_CONTRACTUAL_FACULTY = 'Contractual Full-Time Faculty';

    /**
     * Contractual full-time faculty are always probationary, get 3 VL / 3 SL /
     * 3 EL that reset every term (when schedules are reset), and receive DTR
     * leave/holiday benefits from day one.
     */
    public function isContractualFaculty(): bool
    {
        return self::isContractualFacultyType($this->employment_type);
    }

    public static function isContractualFacultyType(?string $employmentType): bool
    {
        return strcasecmp(trim((string) $employmentType), self::TYPE_CONTRACTUAL_FACULTY) === 0;
    }

    /**
     * Employee IDs are compared without dashes or spaces, so "23-8041" and
     * "238041" refer to the same person.
     */
    public static function normalizeEmployeeId(?string $id): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]/', '', trim((string) $id)) ?? '';

        return strtoupper($normalized);
    }

    /**
     * Apply the department defaults used when saving an employee: SHS
     * positions belong to SHS, faculty keep the chosen school, and Admin
     * Support roles are placed under ASP.
     *
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public static function withDefaultDepartment(array $payload): array
    {
        $position = Str::lower((string) ($payload['position'] ?? ''));

        if (Str::contains($position, '(shs)')) {
            $payload['department_id'] = Department::shsId();

            return $payload;
        }

        if (Str::contains(Str::lower((string) ($payload['employment_type'] ?? '')), 'faculty')) {
            return $payload;
        }

        $facultyPositions = array_map(
            fn ($p) => Str::lower((string) $p),
            (array) config('hris.faculty_positions', [])
        );

        if (in_array($position, $facultyPositions, true)) {
            return $payload;
        }

        $aspDepartmentId = Department::query()->where('name', 'ASP')->value('id');

        if (filled($aspDepartmentId)) {
            $payload['department_id'] = $aspDepartmentId;
        }

        return $payload;
    }

    public static function generateEmployeeId(?int $year = null): string
    {
        $year ??= now()->year;
        $yearToken = (string) $year;

        $latestSequence = static::withTrashed()
            ->pluck('employee_id')
            ->map(function (string $employeeId) use ($yearToken): ?int {
                if (! preg_match('/(?:^|\\D)'.preg_quote($yearToken, '/').'-?(\\d+)(?:\\D|$)/', $employeeId, $matches)) {
                    return null;
                }

                return (int) $matches[1];
            })
            ->filter()
            ->max() ?? 0;

        return sprintf('%s-%03d', $year, $latestSequence + 1);
    }

    protected $fillable = [
        'employee_id',
        'first_name',
        'last_name',
        'email',
        'department_id',
        'position',
        'employment_type',
        'employment_status',
        'ranking',
        'status',
        'hire_date',
        'resume_last_updated_at',
        'leave_term_started_at',
    ];

    protected function casts(): array
    {
        return [
            'hire_date' => 'date',
            'resume_last_updated_at' => 'date',
            'leave_term_started_at' => 'date',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class);
    }

    public function getFullNameAttribute(): string
    {
        return trim($this->first_name.' '.$this->last_name);
    }

    public function credentials(): HasMany
    {
        return $this->hasMany(EmployeeCredential::class);
    }

    public function latestResumeCredential(): HasOne
    {
        return $this->hasOne(EmployeeCredential::class)
            ->where('credential_type', 'resume')
            ->where('status', 'verified')
            ->latestOfMany('updated_at');
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    public function wfhMonitoringSubmissions(): HasMany
    {
        return $this->hasMany(WfhMonitoringSubmission::class);
    }

    public function leaveBalances(): HasMany
    {
        return $this->hasMany(LeaveBalance::class);
    }

    public function leaveRequests(): HasMany
    {
        return $this->hasMany(LeaveRequest::class);
    }

    public function scheduleSubmissions(): HasMany
    {
        return $this->hasMany(EmployeeScheduleSubmission::class);
    }

    public function approvedScheduleSubmission(): HasMany
    {
        return $this->hasMany(EmployeeScheduleSubmission::class)->where('status', EmployeeScheduleSubmission::STATUS_APPROVED);
    }

    public function resumeExpiresAt(): ?Carbon
    {
        return $this->latestResumeCredential?->expires_at;
    }

    public function isResumeExpiringSoon(?Carbon $referenceDate = null): bool
    {
        $expiresAt = $this->resumeExpiresAt();

        if (! $expiresAt) {
            return false;
        }

        $referenceDate ??= now();

        return $expiresAt->betweenIncluded(
            $referenceDate->copy()->startOfDay(),
            $referenceDate->copy()->addDays(30)->endOfDay()
        );
    }

    public function isResumeExpired(?Carbon $referenceDate = null): bool
    {
        $expiresAt = $this->resumeExpiresAt();

        if (! $expiresAt) {
            return false;
        }

        $referenceDate ??= now();

        return $expiresAt->lt($referenceDate->copy()->startOfDay());
    }

    public function latestResumeUpdatedAt(): ?Carbon
    {
        return $this->latestResumeCredential?->updated_at?->copy();
    }
}
