@extends('layouts.guest')

@section('title', 'Create account')

@section('content')
    <h1 class="h4 mb-1">Create your account</h1>
    <p class="text-body-secondary mb-4">It only takes a minute.</p>

    <form action="{{ route('register') }}" method="post" novalidate>
        @csrf

        <div class="mb-3">
            <label for="name" class="form-label">Full name</label>
            <input type="text" id="name" name="name" value="{{ old('name') }}" maxlength="100"
                   @class(['form-control', 'is-invalid' => error('name')]) autocomplete="name" required autofocus>
            @error('name') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label for="email" class="form-label">Email address</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" maxlength="255"
                   @class(['form-control', 'is-invalid' => error('email')]) autocomplete="email" required>
            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label for="password" class="form-label">Password</label>
            <input type="password" id="password" name="password" maxlength="72"
                   @class(['form-control', 'is-invalid' => error('password')]) autocomplete="new-password" required>
            <div class="form-text">8+ characters with upper and lower case letters, a number and a symbol.</div>
            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <div class="mb-3">
            <label for="password_confirmation" class="form-label">Confirm password</label>
            <input type="password" id="password_confirmation" name="password_confirmation" maxlength="72"
                   class="form-control" autocomplete="new-password" required>
        </div>

        <div class="form-check mb-4">
            <input @class(['form-check-input', 'is-invalid' => error('terms')]) type="checkbox" name="terms" value="1" id="terms" @checked(old('terms'))>
            <label class="form-check-label" for="terms">I accept the terms and conditions</label>
            @error('terms') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100">Create account</button>
    </form>
@endsection

@section('below')
    Already registered? <a href="{{ route('login') }}">Log in</a>
@endsection
