@extends('layouts.guest')

@section('title', 'Confirm your email')

@section('content')
    <h1 class="h4 mb-2">Confirm your email address</h1>
    <p class="text-body-secondary">
        We sent a confirmation link to <strong>{{ auth()->email }}</strong>. Click it to activate your account.
        The link expires in 60 minutes.
    </p>
    @if (config('mail.default') === 'log')
        <div class="alert alert-info small">
            Development tip: emails are written to <code>storage/logs/mail.log</code> (MAIL_MAILER=log).
        </div>
    @endif

    <div class="d-flex flex-wrap gap-2 justify-content-between mt-4">
        <form action="{{ route('verification.send') }}" method="post">
            @csrf
            <button type="submit" class="btn btn-primary">Resend the email</button>
        </form>
        <form action="{{ route('logout') }}" method="post">
            @csrf
            <button type="submit" class="btn btn-link">Log out</button>
        </form>
    </div>
@endsection
