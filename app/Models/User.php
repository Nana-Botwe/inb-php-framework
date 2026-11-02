<?php

declare(strict_types=1);

namespace App\Models;

use Core\Auth\Authorizable;
use Core\Auth\HasApiTokens;
use Core\Auth\MustVerifyEmail;
use Core\Auth\TwoFactorAuthenticatable;
use Core\Database\HasFactory;
use Core\Database\Relations\HasMany;
use Core\Model;
use Core\Storage;

/**
 * @property int                     $id
 * @property string                  $name
 * @property string                  $email
 * @property string                  $password
 * @property string                  $role
 * @property string|null             $avatar
 * @property \DateTimeImmutable|null $email_verified_at
 * @property string                  $created_at
 */
final class User extends Model
{
    use Authorizable;
    use HasApiTokens;
    use HasFactory;
    use MustVerifyEmail;
    use TwoFactorAuthenticatable;

    protected static string $table = 'users';

    /** "role" is NOT fillable: nobody can make themselves admin by adding role=admin to a form. */
    protected static array $fillable = ['name', 'email', 'password'];

    protected static array $hidden = ['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'security_stamp'];

    protected static array $casts = [
        'email_verified_at' => 'datetime',
        'password' => 'hashed',
        'two_factor_secret' => 'encrypted',
        'two_factor_recovery_codes' => 'encrypted',
        'two_factor_confirmed_at' => 'datetime',
    ];

    public function payments(): HasMany
    {
        return $this->hasMany(Payment::class);
    }

    public function hasRole(string ...$roles): bool
    {
        return in_array((string) $this->role, $roles, true);
    }

    public function isAdmin(): bool
    {
        return $this->hasRole('admin');
    }

    public function avatarUrl(): ?string
    {
        return $this->avatar ? Storage::disk('public')->url((string) $this->avatar) : null;
    }

    public function initials(): string
    {
        $parts = preg_split('/\s+/', trim((string) $this->name)) ?: [];

        return strtoupper(mb_substr($parts[0] ?? '?', 0, 1) . mb_substr($parts[1] ?? '', 0, 1));
    }
}
