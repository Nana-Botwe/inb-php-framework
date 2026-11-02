@extends('layouts.guest')

@section('title', 'Log in')

@section('content')
    <h1 class="h4 mb-1">Welcome back</h1>
    <p class="text-body-secondary mb-4">Sign in to your account.</p>

    <form action="{{ route('login') }}" method="post" novalidate>
        @csrf

        <div class="mb-3">
            <label for="email" class="form-label">Email address</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" maxlength="255"
                   @class(['form-control', 'is-invalid' => error('email')]) autocomplete="username" required autofocus>
            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <div class="d-flex justify-content-between">
                <label for="password" class="form-label">Password</label>
                <a href="{{ route('password.request') }}" class="small">Forgot password?</a>
            </div>
            <input type="password" id="password" name="password" maxlength="255"
                   @class(['form-control', 'is-invalid' => error('password')]) autocomplete="current-password" required>
            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="form-check mb-4">
            <input class="form-check-input" type="checkbox" name="remember" value="1" id="remember">
            <label class="form-check-label" for="remember">Remember me on this device</label>
        </div>

        <button type="submit" class="btn btn-primary w-100">Log in</button>
    </form>
@endsection

@section('below')
    No account yet? <a href="{{ route('register') }}">Create one</a>
@endsection
