<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\Storage;

class User extends Authenticatable
{
    use HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'password',
        'role',
        'title',
        'avatar',
        'is_active',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_active' => 'boolean',
        ];
    }

    public const ROLES = [
        'admin' => 'Administrator',
        'supervisor_qc' => 'Supervisor QC',
        'operator' => 'Operator',
        'viewer' => 'Viewer',
    ];

    /**
     * Human readable label for the user's role.
     */
    public function roleLabel(): string
    {
        return self::ROLES[$this->role] ?? ucfirst((string) $this->role);
    }

    /**
     * Initials used for the avatar placeholder.
     */
    public function initials(): string
    {
        return collect(explode(' ', (string) $this->name))
            ->filter()
            ->take(2)
            ->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))
            ->implode('');
    }

    /**
     * Get the full URL for the user's avatar.
     */
    public function avatarUrl(): ?string
    {
        if (empty($this->avatar)) {
            return null;
        }

        if (str_starts_with($this->avatar, 'assets/')) {
            return asset($this->avatar);
        }

        return Storage::url($this->avatar);
    }

    public function canAccess(string $module): bool
    {
        if (in_array($this->role, ['admin', 'supervisor_qc', 'operator'])) {
            $access = RolePermission::matrix()[$this->role][$module] ?? '-';
            return $access !== '-';
        }
        return false;
    }

    public function canWrite(string $module): bool
    {
        if ($this->role === 'admin') {
            return true;
        }
        $access = RolePermission::matrix()[$this->role][$module] ?? '-';
        return in_array($access, ['w', 'f'], true);
    }
}
