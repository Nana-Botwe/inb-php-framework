<?php

declare(strict_types=1);

namespace Core\Auth;

use Core\Config;
use Core\Log;
use Core\Token;

/**
 * Add to the User model (with the "encrypted" cast on two_factor_secret / two_factor_recovery_codes).
 */
trait TwoFactorAuthenticatable
{
    public function hasTwoFactorEnabled(): bool
    {
        return ($this->getAttributes()['two_factor_confirmed_at'] ?? null) !== null
            && ($this->getAttributes()['two_factor_secret'] ?? null) !== null;
    }

    /** Must this user use two-factor authentication (config auth.two_factor.required_roles)? */
    public function requiresTwoFactor(): bool
    {
        return in_array((string) ($this->role ?? ''), (array) Config::get('auth.two_factor.required_roles', []), true);
    }

    /**
     * Start setup: store a new (unconfirmed) secret and recovery codes. Returns the plain recovery codes ONCE.
     *
     * @return string[]
     */
    public function enableTwoFactor(): array
    {
        $codes = TwoFactor::generateRecoveryCodes((int) Config::get('auth.two_factor.recovery_codes', 8));

        $this->forceFill([
            'two_factor_secret' => TwoFactor::generateSecret(),
            'two_factor_recovery_codes' => $codes['hashed'],
            'two_factor_confirmed_at' => null,
        ])->save();

        return $codes['plain'];
    }

    /** Finish setup with a code from the app. */
    public function confirmTwoFactor(string $code): bool
    {
        $secret = (string) $this->two_factor_secret;
        if ($secret === '' || !TwoFactor::verify($secret, $code, 'user:' . $this->getKey())) {
            return false;
        }

        $this->forceFill(['two_factor_confirmed_at' => date('Y-m-d H:i:s')])->save();
        Log::security('two_factor_enabled', ['target_user' => $this->getKey()]);

        return true;
    }

    public function disableTwoFactor(): void
    {
        $this->forceFill(['two_factor_secret' => null, 'two_factor_recovery_codes' => null, 'two_factor_confirmed_at' => null])->save();
        Log::security('two_factor_disabled', ['target_user' => $this->getKey()]);
    }

    /** @return string[] New plain recovery codes (old ones stop working). */
    public function regenerateRecoveryCodes(): array
    {
        $codes = TwoFactor::generateRecoveryCodes((int) Config::get('auth.two_factor.recovery_codes', 8));
        $this->forceFill(['two_factor_recovery_codes' => $codes['hashed']])->save();

        return $codes['plain'];
    }

    /** Verify an app code or a (single-use) recovery code. */
    public function verifyTwoFactor(string $code): bool
    {
        if (!$this->hasTwoFactorEnabled()) {
            return false;
        }

        $code = trim($code);
        if (preg_match('/^\d{6}$/', (string) preg_replace('/\s+/', '', $code))) {
            return TwoFactor::verify((string) $this->two_factor_secret, $code, 'user:' . $this->getKey());
        }

        $hashes = (array) ($this->two_factor_recovery_codes ?? []);
        foreach ($hashes as $index => $hash) {
            if (is_string($hash) && Token::matches($hash, $code)) {
                unset($hashes[$index]);   // single use
                $this->forceFill(['two_factor_recovery_codes' => array_values($hashes)])->save();
                Log::security('two_factor_recovery_code_used', ['target_user' => $this->getKey(), 'remaining' => count($hashes)]);

                return true;
            }
        }

        return false;
    }

    public function twoFactorQrUri(): string
    {
        return TwoFactor::otpauthUri((string) $this->two_factor_secret, (string) $this->email, (string) Config::get('auth.two_factor.issuer', 'App'));
    }
}
