<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

#[Fillable(['name', 'email', 'password', 'user_type'])]
#[Hidden(['password', 'remember_token'])]
class User extends Authenticatable
{
    public const TYPE_ADMIN = 1;
    public const TYPE_HR = 2;
    public const TYPE_EMPLOYEE = 3;

    /** @use HasFactory<UserFactory> */
    use HasFactory, Notifiable;

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'user_type' => 'integer',
        ];
    }

    public function isAdmin(): bool
    {
        return $this->user_type === self::TYPE_ADMIN;
    }

    public function isHr(): bool
    {
        return $this->user_type === self::TYPE_HR;
    }

    public function isEmployee(): bool
    {
        return $this->user_type === self::TYPE_EMPLOYEE;
    }

    /**
     * Roles are hierarchical: Admin can open the Admin, HR and Employee portals,
     * HR can open the HR and Employee portals, Employee only the Employee portal.
     */
    public function canAccessPortal(int $portalType): bool
    {
        return in_array($this->user_type, [self::TYPE_ADMIN, self::TYPE_HR, self::TYPE_EMPLOYEE], true)
            && $this->user_type <= $portalType;
    }

    /**
     * @return array<int, array{label: string, route: string, prefix: string}>
     */
    public function accessiblePortals(): array
    {
        $portals = [
            self::TYPE_ADMIN => ['label' => 'Admin Portal', 'route' => 'admin.dashboard', 'prefix' => 'admin'],
            self::TYPE_HR => ['label' => 'HR Portal', 'route' => 'dashboard', 'prefix' => 'hr'],
            self::TYPE_EMPLOYEE => ['label' => 'Employee Portal', 'route' => 'employee.dashboard', 'prefix' => 'employee'],
        ];

        return array_values(array_filter(
            $portals,
            fn (int $portalType) => $this->canAccessPortal($portalType),
            ARRAY_FILTER_USE_KEY,
        ));
    }

    public function announcementsCreated(): HasMany
    {
        return $this->hasMany(Announcement::class, 'created_by');
    }

    public function announcementNotifications(): HasMany
    {
        return $this->hasMany(AnnouncementNotification::class);
    }

    public function privacyNoticeAcknowledgments(): HasMany
    {
        return $this->hasMany(PrivacyNoticeAcknowledgment::class);
    }

    public function visibleAnnouncementNotifications(): HasMany
    {
        return $this->announcementNotifications()->visible();
    }

    public function unreadAnnouncementNotifications(): HasMany
    {
        return $this->visibleAnnouncementNotifications()->where('is_read', false);
    }

    public function employeeProfile(): HasOne
    {
        return $this->hasOne(Employee::class, 'email', 'email');
    }
}
