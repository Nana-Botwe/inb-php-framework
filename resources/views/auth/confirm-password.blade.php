@extends('layouts.guest')

@section('title', 'Confirm password')

@section('content')
    <h1 class="h4 mb-1">Confirm your password</h1>
    <p class="text-body-secondary mb-4">This is a protected area. Please confirm your password before continuing.</p>

    <form action="{{ route('password.confirm') }}" method="post" novalidate>
        @csrf
        <div class="mb-4">
            <label for="password" class="form-label">Password</label>
            <input type="password" id="password" name="password" autocomplete="current-password" maxlength="255"
                   @class(['form-control', 'is-invalid' => error('password')]) required autofocus>
            @error('password') <div class="invalid-feedback">{{ $message }}</div> @enderror
        </div>
        <button type="submit" class="btn btn-primary w-100">Confirm</button>
    </form>
@endsection
