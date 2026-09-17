<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class AdminAuditLog extends Model
{
    use HasFactory;

    protected $fillable = [
        'user_id',
        'action',
        'module',
        'description',
        'status',
        'metadata',
    ];

    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function roleLabel(): string
    {
        if (! $this->user) {
            return 'System';
        }

        return match ((int) $this->user->user_type) {
            User::TYPE_ADMIN => 'Admin',
            User::TYPE_HR => 'HR Personnel',
            User::TYPE_EMPLOYEE => 'Employee',
            default => 'System',
        };
    }
}
