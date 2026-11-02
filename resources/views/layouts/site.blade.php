<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="description" content="@yield('description', 'INB PHP Framework — a secure, modern PHP framework by INB IT Firm.')">
    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name') }}</title>
    @include('partials.theme-head')
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/site.css') }}">
</head>
<body>
<a class="skip" href="#main">Skip to content</a>
<div class="glow" aria-hidden="true"></div>

<div class="wrap">
    <header class="site-header">
        <a href="{{ route('home') }}" class="brand" aria-label="{{ config('app.name') }} home">
            @include('partials.logo', ['size' => 38])
            <span>INB PHP Framework<small>by INB IT Firm</small></span>
        </a>
        <nav class="site-nav" aria-label="Main">
            <a class="btn ghost" href="{{ route('docs.index') }}">Docs</a>
            @auth
                <a class="btn primary" href="{{ route('dashboard') }}">Dashboard</a>
            @else
                <a class="btn" href="{{ route('login') }}">Log in</a>
                <a class="btn primary" href="{{ route('register') }}">Register</a>
            @endauth
            @include('partials.theme-toggle')
        </nav>
    </header>

    <main id="main">
        @yield('content')
    </main>

    <footer class="site-footer">
        <span>&copy; {{ date('Y') }} INB IT Firm · INB PHP Framework v{{ \Core\App::VERSION }}</span>
        <span>PHP v{{ PHP_VERSION }} · {{ config('app.env') }} environment</span>
    </footer>
</div>

<script src="{{ asset('assets/js/theme.js') }}" defer></script>
@stack('scripts')
</body>
</html>
