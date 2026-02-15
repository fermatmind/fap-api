<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Filament\Models\Contracts\FilamentUser;
use Filament\Panel;

class AdminUser extends Authenticatable implements FilamentUser
{
    use HasFactory, Notifiable;

    protected $table = 'admin_users';

    protected $fillable = [
        'name',
        'email',
        'password',
        'totp_secret',
        'totp_enabled_at',
        'preferred_locale',
        'password_changed_at',
        'failed_login_count',
        'locked_until',
        'is_active',
        'last_login_at',
    ];

    protected $hidden = [
        'password',
        'totp_secret',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'password' => 'hashed',
            'is_active' => 'integer',
            'failed_login_count' => 'integer',
            'last_login_at' => 'datetime',
            'totp_enabled_at' => 'datetime',
            'password_changed_at' => 'datetime',
            'locked_until' => 'datetime',
        ];
    }

    public function roles()
    {
        return $this->belongsToMany(Role::class, 'role_user');
    }

    public function hasRole(string $name): bool
    {
        return $this->roles()->where('name', $name)->exists();
    }

    public function hasPermission(string $permissionName): bool
    {
        return $this->roles()
            ->whereHas('permissions', function ($query) use ($permissionName) {
                $query->where('name', $permissionName);
            })
            ->exists();
    }

    public function canAccessPanel(Panel $panel): bool
    {
        if ((int) $this->is_active !== 1) {
            return false;
        }

        if ($this->locked_until !== null && $this->locked_until->isFuture()) {
            return false;
        }

        return true;
    }
}
