<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Config;
use Core\DB;
use Core\ErrorHandler;
use Core\Event;
use Core\Log;
use Core\Mail\Mail;
use Core\Model;
use Core\RateLimiter;
use Core\Support\Str;
use Core\Token;

/**
 * Password reset broker.
 *
 *   Password::sendResetLink($email);                  // ALWAYS looks the same to the visitor
 *   Password::reset($email, $token, $newPassword);    // PASSWORD_RESET or INVALID_TOKEN
 *
 * - Tokens are random (64 chars), stored only as HMAC hashes, expire after 60 minutes.
 * - Throttling is applied to EVERY address (registered or not), so responses can't reveal which exist.
 * - The email is queued (Mail::queue): SMTP speed or errors never reach the response.
 * - A token is consumed with one conditional DELETE inside the same transaction as the password update:
 *   two concurrent requests with the same token can never both succeed.
 * - A successful reset revokes every session, the remember-me token and API tokens (Credentials).
 */
final class Password
{
    public const RESET_LINK_SENT = 'passwords.sent';

    public const PASSWORD_RESET = 'passwords.reset';

    public const INVALID_TOKEN = 'passwords.token';

    /** Internal only — controllers must show RESET_LINK_SENT for this too. */
    public const THROTTLED = 'passwords.throttled';

    private const TABLE = 'password_reset_tokens';

    public static function sendResetLink(string $email): string
    {
        $email = strtolower(trim($email));
        $throttleKey = 'password-reset:' . hash('sha256', $email);
        $throttleSeconds = max(1, (int) Config::get('auth.passwords.throttle', 60));

        // Same throttle for existing and unknown addresses: the outcome reveals nothing.
        if (RateLimiter::tooManyAttempts($throttleKey, 1)) {
            Log::security('password_reset_throttled');

            return self::THROTTLED;
        }
        RateLimiter::hit($throttleKey, $throttleSeconds);

        $model = (string) Config::get('auth.model');
        $user = $model::where('email', $email)->first();

        if (!$user instanceof Model) {
            Log::security('password_reset_unknown_email');

            return self::RESET_LINK_SENT;
        }

        $token = Str::random(64);
        DB::transaction(static function () use ($email, $token): void {
            DB::table(self::TABLE)->where('email', $email)->delete();
            DB::table(self::TABLE)->insert(['email' => $email, 'token' => Token::hash($token), 'created_at' => date('Y-m-d H:i:s')]);
        });

        // Queued: a slow or failing mail server must not make this answer slower, different or an error —
        // that would reveal which addresses have accounts. Failures are logged and retried by the worker.
        try {
            Mail::to($email)->queue(new \App\Mail\ResetPassword($user, $token));
        } catch (\Throwable $e) {
            ErrorHandler::report($e);
        }
        Log::security('password_reset_requested');

        return self::RESET_LINK_SENT;
    }

    /** Read-only check (e.g. to show the form). The real check happens atomically in reset(). */
    public static function tokenIsValid(string $email, string $token): bool
    {
        $row = DB::table(self::TABLE)->where('email', strtolower(trim($email)))->first();

        return $row !== null
            && strtotime((string) $row['created_at']) >= time() - self::expirySeconds()
            && Token::matches((string) $row['token'], $token);
    }

    public static function reset(string $email, string $token, string $password): string
    {
        $email = strtolower(trim($email));
        $model = (string) Config::get('auth.model');
        $user = $model::where('email', $email)->first();

        if (!$user instanceof Model) {
            Log::security('password_reset_invalid_token');

            return self::INVALID_TOKEN;
        }

        $done = DB::transaction(static function () use ($email, $token, $password, $user): bool {
            // Consume the token: only ONE request can delete this row. Anyone else gets 0 rows.
            $consumed = DB::table(self::TABLE)
                ->where('email', $email)
                ->whereIn('token', Token::hashCandidates($token))
                ->where('created_at', '>=', date('Y-m-d H:i:s', time() - self::expirySeconds()))
                ->delete();

            if ($consumed !== 1) {
                return false;
            }

            Credentials::changePassword($user, $password, keepCurrentSession: false, reason: 'password_reset');

            return true;
        });

        if (!$done) {
            Log::security('password_reset_invalid_token');

            return self::INVALID_TOKEN;
        }

        Event::dispatch('auth.password_reset', [$user]);
        Log::security('password_reset_completed');

        return self::PASSWORD_RESET;
    }

    private static function expirySeconds(): int
    {
        return (int) Config::get('auth.passwords.expire', 60) * 60;
    }
}
