<?php

/*
|--------------------------------------------------------------------------
| Request firewall (Core\Firewall) — runs on every request before routing
|--------------------------------------------------------------------------
| Blocked requests get 403 (or 404 for secret-file probes) and are written to
| storage/logs/security-YYYY-MM-DD.log.
*/

return [
    'enabled' => (bool) env('FIREWALL_ENABLED', true),

    // Never blocked (e.g. your office IP). Exact matches.
    'allow_ips' => [],

    // Always blocked. Exact matches.
    'block_ips' => [],

    // Ban an IP for "ban_minutes" after this many blocked requests.
    'ban_after' => 10,
    'ban_minutes' => 60,

    'max_url_length' => 2048,

    // Also scan POST form fields for attack patterns. Off by default because normal text
    // (e.g. a message that mentions "select ... from") can trigger it. Output escaping and
    // prepared statements already make those values harmless.
    'scan_request_body' => false,

    // Paths (lower-case, relative to the app) that only attackers ask for.
    'blocked_paths' => [
        '#(^|/)\.(?!well-known/)#',                                  // .env, .git, .htaccess, .DS_Store ...
        '#\.(env|ini|log|sql|sqlite|bak|backup|old|orig|save|swp|tmp|dist|lock|ya?ml|conf|config|inc|phar|sh|bat|ps1)$#',
        '#\.php\d?$#',                                               // the app has no direct .php URLs
        '#^/(app|bootstrap|config|core|database|routes|storage|vendor|backup|node_modules)(/|$)#',
        '#^/(composer\.(json|lock)|package(-lock)?\.json|console|readme\.md)$#',
        '#(^|/)(wp-admin|wp-login|wp-content|wp-includes|xmlrpc|phpmyadmin|pma|myadmin|adminer|cgi-bin|phpinfo|server-status|server-info|webdav|\.well-known/security\.txt\.php)(/|\.|$)#',
    ],

    // Regexes matched against the decoded URL + query string.
    'attack_patterns' => [
        'sql_injection' => '/(\bunion\b[\s\/*()]+(all[\s\/*()]+)?select\b|\bselect\b.+\bfrom\b.+\b(information_schema|mysql\.user|pg_catalog|sqlite_master)\b|\b(sleep|benchmark|pg_sleep|load_file)\s*\(|\binto\s+(out|dump)file\b|\bwaitfor\s+delay\b|(\'|")\s*(or|and)\s*(\'|")?\d+(\'|")?\s*=\s*(\'|")?\d+|;\s*(drop|truncate|alter|insert|update|delete)\s+\w+|--\s*$|\/\*.*\*\/)/i',
        'xss' => '/(<\s*\/?\s*(script|iframe|object|embed|svg|math|base|meta|link|style)\b|javascript\s*:|vbscript\s*:|data\s*:\s*text\/html|\bon(load|error|click|mouse\w+|focus|blur|submit|change|key\w+|animation\w+|toggle|pointer\w+)\s*=|expression\s*\()/i',
        'php_injection' => '/(<\?php|<\?=|\b(eval|assert|system|exec|shell_exec|passthru|popen|proc_open|base64_decode|gzinflate|str_rot13|create_function|call_user_func)\s*\(|\b(php|phar|zip|data|expect|glob|file|input):\/\/)/i',
        'file_inclusion' => '/(\/etc\/(passwd|shadow|hosts)|c:\\\\windows|\bboot\.ini\b|\/proc\/self\/|(https?|ftp):\/\/[^\s]*\?(\S*)=)/i',
        'command_injection' => '/(;|\||`|\$\(|&&)\s*(cat|ls|id|whoami|uname|wget|curl|nc|bash|sh|powershell|cmd)\b/i',
        'template_injection' => '/(\{\{.*\}\}|\$\{.*\}|<%.*%>)/',
    ],

    // Known attack/scanner tools. Matched case-insensitively against User-Agent.
    'blocked_user_agents' => [
        'sqlmap', 'nikto', 'nmap', 'masscan', 'acunetix', 'nessus', 'openvas', 'wpscan',
        'dirbuster', 'gobuster', 'feroxbuster', 'havij', 'netsparker', 'zgrab', 'jaeles', 'nuclei',
    ],
];
