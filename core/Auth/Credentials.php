<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Auth;
use Core\Config;
use Core\Event;
use Core\Hash;
use Core\Log;
use Core\Model;
use Core\QueryBuilder;
use Core\Response;
use Core\Support\Str;

/**
 * Credential-revocation policy — the single place that decides what stops working when a password
 * changes or an account must be secured. Controllers call this instead of updating passwords themselves.
 *
 * On every password change / reset / "sign out everywhere":
 *   1. the security stamp rotates          → every existing session on every device is invalid
 *   2. the remember-me token is cleared    → old "remember me" cookies can't start new sessions
 *   3. API tokens are deleted              → (config auth.revoke_tokens_on_password_change, default on)
 *   4. the event "auth.credentials_revoked" fires and a security-log entry is written
 * The device that made the change can stay signed in with a fresh session (password change), or not (reset).
 */
final class Credentials
{
    public static function changePassword(Model $user, string $newPassword, bool $keepCurrentSession = true, string $reason = 'password_changed'): void
    {
        $user->forceFill([
            'password' => Hash::make($newPassword),
            'password_changed_at' => date('Y-m-d H:i:s'),
        ]);

        self::revoke($user, $reason, $keepCurrentSession);
    }

    /**
     * Revoke every credential of the user without changing the password (e.g. "sign out of all devices",
     * an admin locking a compromised account).
     */
    public static function revoke(Model $user, string $reason = 'revoked', bool $keepCurrentSession = false): void
    {
        $attributes = $user->getAttributes();
        $changes = ['security_stamp' => Str::random(64)];
        if (array_key_exists('remember_token', $attributes) || self::hasColumn($user, 'remember_token')) {
            $changes['remember_token'] = null;
        }

        $user->forceFill(self::onlyExistingColumns($user, $changes))->save();

        $tokens = 0;
        if (Config::get('auth.revoke_tokens_on_password_change', true)) {
            $tokens = self::tokensQuery($user)->exists() ? self::tokensQuery($user)->delete() : 0;
        }

        $isCurrentUser = (string) Auth::id() === (string) $user->getKey();
        if ($isCurrentUser) {
            Response::forgetCookie('remember_web');
            if ($keepCurrentSession) {
                Auth::refreshSession($user);
            } else {
                Auth::logout();
            }
        }

        Log::security('credentials_revoked', ['target_user' => $user->getKey(), 'reason' => $reason, 'api_tokens' => $tokens]);
        Event::dispatch('auth.credentials_revoked', [$user, $reason]);
    }

    private static function tokensQuery(Model $user): QueryBuilder
    {
        return (new QueryBuilder($user::connection(), 'personal_access_tokens'))
            ->where('tokenable_type', $user::class)
            ->where('tokenable_id', $user->getKey());
    }

    /** @param array<string, mixed> $changes @return array<string, mixed> */
    private static function onlyExistingColumns(Model $user, array $changes): array
    {
        return array_filter($changes, static fn ($column) => self::hasColumn($user, (string) $column), ARRAY_FILTER_USE_KEY);
    }

    /** @var array<string, string[]> */
    private static array $columns = [];

    private static function hasColumn(Model $user, string $column): bool
    {
        if (array_key_exists($column, $user->getAttributes())) {
            return true;
        }

        $key = $user::connection()->getName() . '.' . $user::table();
        if (!isset(self::$columns[$key])) {
            self::$columns[$key] = array_map('strtolower', (new \Core\Database\SchemaBuilder($user::connection()))->getColumns($user::table()));
        }

        return in_array(strtolower($column), self::$columns[$key], true);
    }

    /** @internal for tests */
    public static function flushColumnCache(): void
    {
        self::$columns = [];
    }
}
