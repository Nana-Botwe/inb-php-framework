@extends('layouts.guest')

@section('title', 'Forgot password')

@section('content')
    <h1 class="h4 mb-1">Forgot your password?</h1>
    <p class="text-body-secondary mb-4">Enter your email and we will send you a link to choose a new one.</p>

    <form action="{{ route('password.email') }}" method="post" novalidate>
        @csrf

        <div class="mb-4">
            <label for="email" class="form-label">Email address</label>
            <input type="email" id="email" name="email" value="{{ old('email') }}" maxlength="255"
                   @class(['form-control', 'is-invalid' => error('email')]) autocomplete="email" required autofocus>
            @error('email') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>

        <button type="submit" class="btn btn-primary w-100">Email me a reset link</button>
    </form>
@endsection

@section('below')
    Remembered it? <a href="{{ route('login') }}">Back to log in</a>
@endsection
