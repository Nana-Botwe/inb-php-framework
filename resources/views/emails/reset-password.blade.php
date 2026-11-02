@extends('emails.layout')

@section('content')
    <h1 style="font-size:22px;margin:0 0 16px;">Reset your password</h1>
    <p>Hi {{ $user->name }},</p>
    <p>We received a request to reset the password for your account.</p>
    @include('emails.partials.button', ['url' => $url, 'label' => 'Choose a new password'])
    <p>This link expires in {{ $minutes }} minutes and can only be used once.</p>
    <p>If you did not ask for this, ignore this email — your password will not change.</p>
@endsection

@section('footer', 'For your security, never share this email with anyone.')
