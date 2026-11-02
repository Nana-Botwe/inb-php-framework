@extends('layouts.guest')

@section('title', 'Two-factor authentication')

@section('content')
    <h1 class="h4 mb-1">Two-factor authentication</h1>
    <p class="text-body-secondary mb-4">Enter the 6-digit code from your authenticator app, or one of your recovery codes.</p>

    <form action="{{ route('two-factor.challenge') }}" method="post" novalidate>
        @csrf
        <div class="mb-4">
            <label for="code" class="form-label">Code</label>
            <input type="text" id="code" name="code" inputmode="numeric" autocomplete="one-time-code" maxlength="32"
                   @class(['form-control form-control-lg text-center', 'is-invalid' => error('code')]) placeholder="123456" required autofocus>
            @error('code') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <button type="submit" class="btn btn-primary w-100">Verify and sign in</button>
    </form>
@endsection

@section('below')
    Lost your device? Use a recovery code. <a href="{{ route('login') }}">Start over</a>
@endsection
