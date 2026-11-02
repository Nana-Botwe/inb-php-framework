@extends('layouts.guest')

@section('title', 'Reset password')

@section('content')
    <h1 class="h4 mb-1">Choose a new password</h1>
    <p class="text-body-secondary mb-4">The link is valid for {{ config('auth.passwords.expire', 60) }} minutes and can be used once.</p>

    <form action="{{ route('password.update') }}" method="post" novalidate>
        @csrf
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="mb-3">
            <label for="email" class="form-label">Email address</label>
            <input type="email" id="email" name="email" value="{{ old('email', $email) }}" maxlength="255"
                   @class(['form-control', 'is-invalid' => error('email')]) autocomplete="username" required>
            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">New password</label>
            <input type="password" id="password" name="password" maxlength="72"
                   @class(['form-control', 'is-invalid' => error('password')]) autocomplete="new-password" required autofocus>
            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-4">
            <label for="password_confirmation" class="form-label">Confirm new password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" maxlength="72" class="form-control" autocomplete="new-password" required>
        </div>

        <button type="submit" class="btn btn-primary w-100">Reset password</button>
    </form>
@endsection
