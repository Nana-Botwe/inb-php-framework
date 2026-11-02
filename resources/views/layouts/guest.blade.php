<!doctype html>
<html lang="{{ config('app.locale', 'en') }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @include('partials.theme-head')
    <title>@hasSection('title')@yield('title') · @endif{{ config('app.name') }}</title>
    <link rel="icon" type="image/svg+xml" href="{{ asset('favicon.svg') }}">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/css/bootstrap.min.css"
          integrity="sha384-QWTKZyjpPEjISv5WaRU9OFeRpok6YctnYmDr5pNlyT2bRjXh0JMhjY6hW+ALEwIH" crossorigin="anonymous">
    <link rel="stylesheet" href="{{ asset('assets/css/app.css') }}">
</head>
<body class="guest-body">
    <div class="guest-toolbar">
        <a href="{{ route('docs.index') }}" class="guest-link">Docs</a>
        @include('partials.theme-toggle')
    </div>
    <main class="container py-5">
        <div class="row justify-content-center">
            <div class="col-sm-10 col-md-7 col-lg-5">
                <a href="{{ route('home') }}" class="d-flex align-items-center justify-content-center gap-2 mb-4 text-decoration-none text-body">
                    @include('partials.logo', ['size' => 44])
                    <span class="fs-5 fw-semibold">{{ config('app.name') }}</span>
                </a>

                <div class="card shadow-sm border-0">
                    <div class="card-body p-4 p-md-5">
                        @include('partials.flash')
                        @yield('content')
                    </div>
                </div>

                @hasSection('below')
                    <p class="text-center small text-body-secondary mt-3">@yield('below')</p>
                @endif
            </div>
        </div>
    </main>

    {{-- Bootstrap JS: needed for dismissible alerts (the close button on messages) --}}
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.3/dist/js/bootstrap.bundle.min.js"
            integrity="sha384-YvpcrYf0tY3lHB60NNkmXc5s9fDVZLESaAA55NDzOxhy9GkcIdslK1eN7N6jIeHz" crossorigin="anonymous"></script>
    <script src="{{ asset('assets/js/app.js') }}"></script>
    <script src="{{ asset('assets/js/theme.js') }}" defer></script>
</body>
</html>
