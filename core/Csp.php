<?php

declare(strict_types=1);

namespace Core;

/**
 * Content-Security-Policy: the browser only runs scripts/styles from the sources listed in
 * config/security.php. Injected <script> tags (XSS) are blocked. Your own inline scripts need
 * the per-request nonce:  <script nonce="<?= csp_nonce() ?>"> ... </script>
 */
final class Csp
{
    private static ?string $nonce = null;

    public static function nonce(): string
    {
        return self::$nonce ??= base64_encode(random_bytes(16));
    }

    /** Build the header value from config, or null when CSP is disabled. */
    public static function header(): ?string
    {
        $directives = Config::get('security.csp');
        if (!is_array($directives) || $directives === []) {
            return null;
        }

        $parts = [];
        foreach ($directives as $name => $value) {
            $parts[] = trim($name . ' ' . str_replace('{nonce}', self::nonce(), (string) $value));
        }

        return implode('; ', $parts);
    }
}
