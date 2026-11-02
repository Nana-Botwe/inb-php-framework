<nav class="navbar navbar-expand-lg bg-body-tertiary border-bottom">
    <div class="container">
        <a class="navbar-brand d-flex align-items-center gap-2 fw-semibold" href="{{ route('home') }}">
            @include('partials.logo', ['size' => 30])
            {{ config('app.name') }}
        </a>
        <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#mainNav"
                aria-controls="mainNav" aria-expanded="false" aria-label="Toggle navigation">
            <span class="navbar-toggler-icon"></span>
        </button>

        <div class="collapse navbar-collapse" id="mainNav">
            <ul class="navbar-nav me-auto">
                @auth
                    <li class="nav-item"><a @class(['nav-link', 'active' => is_active('dashboard')]) href="{{ route('dashboard') }}">Dashboard</a></li>
                    <li class="nav-item"><a @class(['nav-link', 'active' => is_active('payments*')]) href="{{ route('payments.create') }}">Payments</a></li>
                    @role('admin')
                        <li class="nav-item"><a @class(['nav-link', 'active' => is_active('admin/*')]) href="{{ route('admin.users.index') }}">Admin</a></li>
                    @endrole
                @endauth
            </ul>

            <ul class="navbar-nav align-items-lg-center gap-lg-2">
                <li class="nav-item"><a @class(['nav-link', 'active' => is_active('docs*')]) href="{{ route('docs.index') }}">Docs</a></li>
                @auth
                    <li class="nav-item dropdown">
                        <a class="nav-link dropdown-toggle d-flex align-items-center gap-2" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            @if (auth()->avatarUrl())
                                <img src="{{ auth()->avatarUrl() }}" alt="" width="28" height="28" class="rounded-circle object-fit-cover">
                            @else
                                <span class="avatar-initials">{{ auth()->initials() }}</span>
                            @endif
                            {{ auth()->name }}
                        </a>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li><a class="dropdown-item" href="{{ route('profile.edit') }}">Profile</a></li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                {{-- Logout is a POST with a CSRF token, so other sites can't sign your users out. --}}
                                <form action="{{ route('logout') }}" method="post">
                                    @csrf
                                    <button type="submit" class="dropdown-item">Sign out</button>
                                </form>
                            </li>
                        </ul>
                    </li>
                @else
                    <li class="nav-item"><a class="nav-link" href="{{ route('login') }}">Log in</a></li>
                    <li class="nav-item"><a class="btn btn-primary btn-sm" href="{{ route('register') }}">Register</a></li>
                @endauth
                <li class="nav-item ms-lg-1">@include('partials.theme-toggle')</li>
            </ul>
        </div>
    </div>
</nav>
