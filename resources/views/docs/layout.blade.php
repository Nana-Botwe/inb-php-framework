<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>@yield('title') · INB PHP Framework Docs</title>
    <meta name="description" content="Documentation for the INB PHP Framework by INB IT Firm.">
    @include('partials.theme-head')
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="{{ asset('assets/css/docs.css') }}">
</head>
<body>
<a class="skip" href="#content">Skip to content</a>

<header class="topbar">
    <button class="menu-btn" type="button" aria-controls="sidebar" aria-expanded="false" data-docs-menu>
        <span class="sr-only">Toggle navigation</span>
        <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M3 6h18M3 12h18M3 18h18"/></svg>
    </button>
    {{-- The logo goes back to the homepage; "Docs" in the sidebar/heading stays in the documentation. --}}
    <a class="brand" href="{{ route('home') }}" title="Back to the homepage">
        @include('partials.logo', ['size' => 30])
        <span>INB PHP Framework <em>Docs</em></span>
    </a>
    <form class="search" action="{{ route('docs.search') }}" method="get" role="search">
        <label for="docs-q" class="sr-only">Search the documentation</label>
        <input id="docs-q" type="search" name="q" value="{{ $query ?? '' }}" placeholder="Search docs…  (press /)" maxlength="80" autocomplete="off">
    </form>
    <nav class="top-links" aria-label="Site">
        <a href="{{ route('home') }}">Home</a>
        <a href="{{ route('docs.index') }}">Docs home</a>
        @auth <a href="{{ route('dashboard') }}">Dashboard</a> @endauth
    </nav>
    @include('partials.theme-toggle')
</header>

<div class="shell">
    <aside class="sidebar" id="sidebar" aria-label="Documentation">
        @foreach ($navigation as $group => $items)
            <div class="nav-group">
                <h2>{{ $group }}</h2>
                <ul>
                    @foreach ($items as $slug => $label)
                        <li><a href="{{ route('docs.show', ['page' => $slug]) }}" @class(['active' => $page === $slug]) @if($page === $slug) aria-current="page" @endif>{{ $label }}</a></li>
                    @endforeach
                </ul>
            </div>
        @endforeach
        <p class="version">v{{ \Core\App::VERSION }} · PHP {{ PHP_VERSION }}</p>
    </aside>

    <main id="content" class="content">
        @yield('content')
    </main>

    @hasSection('toc')
        <aside class="toc" aria-label="On this page">
            <h2>On this page</h2>
            @yield('toc')
        </aside>
    @endif
</div>

<script src="{{ asset('assets/js/theme.js') }}" defer></script>
<script src="{{ asset('assets/js/docs.js') }}" defer></script>
</body>
</html>
