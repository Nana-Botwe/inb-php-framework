@php
    $pages = [
        400 => ['Bad request', 'The request could not be understood. Please check the address or form and try again.', '#f59e0b'],
        401 => ['Please sign in', 'You need to be signed in to see this page.', '#3b82f6'],
        403 => ['Access denied', 'You don\'t have permission to view this page. If you think this is a mistake, contact the administrator.', '#ef4444'],
        404 => ['Page not found', 'The page you are looking for doesn\'t exist or has been moved.', '#8b5cf6'],
        405 => ['Not allowed', 'This action is not allowed on this page.', '#f59e0b'],
        413 => ['File too large', 'The upload is larger than the server accepts. Please choose a smaller file.', '#f59e0b'],
        414 => ['Address too long', 'The web address is too long to process.', '#f59e0b'],
        419 => ['Page expired', 'For your security the form expired. Please go back, refresh the page and try again.', '#0ea5e9'],
        422 => ['Check your input', 'Some of the information you sent is not valid.', '#f59e0b'],
        429 => ['Slow down', 'Too many requests in a short time. Please wait a moment and try again.', '#f97316'],
        500 => ['Something went wrong', 'An unexpected error happened on our side. Our team has been notified.', '#e11d48'],
        503 => ['Back soon', 'We are doing some maintenance. Please check back in a few minutes.', '#14b8a6'],
    ];
    [$title, $description, $accent] = $pages[$status] ?? ['Error', 'Something went wrong.', '#e11d48'];
    // Show the specific reason for 4xx errors (it never contains internals); 5xx keeps the safe text.
    $detail = $status < 500 && $message !== '' && $message !== \Core\HttpException::reason($status) ? $message : null;
    $previous = \Core\Session::get('_previous_url');
    $backUrl = \Core\Response::isSafePath($previous) ? url((string) $previous) : url('/');
@endphp
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @include('partials.theme-head')
    <title>{{ $status }} · {{ $title }} · {{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <style nonce="@nonce">
        :root { --bg: #f7f8fb; --card: #fff; --text: #0f172a; --muted: #64748b; --line: #e5e7eb; --accent: {{ $accent }}; }
        :root[data-theme="dark"] { --bg: #0a0a0f; --card: #12121a; --text: #f1f5f9; --muted: #94a3b8; --line: #232332; }
        @media (prefers-color-scheme: dark) { :root:not([data-theme="light"]) { --bg: #0a0a0f; --card: #12121a; --text: #f1f5f9; --muted: #94a3b8; --line: #232332; } }
        .theme-toggle { position: fixed; top: 16px; right: 16px; display: inline-flex; align-items: center; justify-content: center; width: 38px; height: 38px; border-radius: 10px; border: 1px solid var(--line); background: var(--card); color: var(--text); cursor: pointer; }
        .theme-toggle .icon-sun, .theme-toggle-label { display: none; }
        :root[data-theme="dark"] .theme-toggle .icon-sun { display: block; }
        :root[data-theme="dark"] .theme-toggle .icon-moon { display: none; }
        * { box-sizing: border-box; }
        body { margin: 0; min-height: 100vh; display: grid; place-items: center; padding: 24px 16px; background: var(--bg); color: var(--text);
               font: 16px/1.6 ui-sans-serif, system-ui, -apple-system, "Segoe UI", Roboto, Arial, sans-serif; }
        .card { width: 100%; max-width: 520px; background: var(--card); border: 1px solid var(--line); border-radius: 20px; padding: 40px 32px; text-align: center;
                box-shadow: 0 20px 50px rgba(15, 23, 42, .08); position: relative; overflow: hidden; }
        .card::before { content: ""; position: absolute; inset: 0 0 auto 0; height: 5px; background: var(--accent); }
        .code { font-size: 76px; line-height: 1; font-weight: 800; letter-spacing: -.04em; margin: 4px 0 10px; color: var(--accent); }
        h1 { font-size: 24px; margin: 0 0 8px; }
        p { color: var(--muted); margin: 0 auto 8px; max-width: 420px; }
        .detail { margin: 16px auto 0; padding: 10px 14px; border-radius: 12px; background: var(--bg); border: 1px solid var(--line); color: var(--text); font-size: 14px; }
        .actions { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; margin-top: 26px; }
        a.btn { padding: 10px 18px; border-radius: 10px; font-weight: 600; font-size: 14px; text-decoration: none; border: 1px solid var(--line); color: var(--text); }
        a.btn.primary { background: var(--accent); border-color: var(--accent); color: #fff; }
        a.btn:hover { filter: brightness(1.05); }
        .ref { display: inline-block; margin-top: 22px; font: 12px ui-monospace, Consolas, monospace; color: var(--muted); border: 1px dashed var(--line); padding: 5px 12px; border-radius: 999px; }
        .brand { margin-top: 26px; font-size: 12px; color: var(--muted); }
    </style>
</head>
<body>
    @include('partials.theme-toggle')
    <main class="card" role="main">
        <div class="code">{{ $status }}</div>
        <h1>{{ $title }}</h1>
        <p>{{ $description }}</p>
        @if ($detail)
            <div class="detail">{{ $detail }}</div>
        @endif

        <div class="actions">
            @if ($status === 401 || $status === 419)
                <a class="btn primary" href="{{ \Core\App::instance()->router->has('login') ? route('login') : url('/') }}">{{ $status === 401 ? 'Sign in' : 'Try again' }}</a>
            @else
                <a class="btn primary" href="{{ url('/') }}">Go to home page</a>
            @endif
            @if ($status !== 503)
                <a class="btn" href="{{ $backUrl }}">Go back</a>
            @endif
        </div>

        @if (!empty($errorId))
            <div class="ref" title="Give this code to support so they can find the exact problem">Error reference: {{ $errorId }}</div>
        @endif
        <div class="brand">{{ config('app.name') }}</div>
    </main>
    <script src="{{ asset('assets/js/theme.js') }}" defer></script>
</body>
</html>
