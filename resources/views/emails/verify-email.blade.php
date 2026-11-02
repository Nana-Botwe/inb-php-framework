@extends('emails.layout')

@section('content')
    <h1 style="font-size:22px;margin:0 0 16px;">Confirm your email address</h1>
    <p>Hi {{ $user->name }},</p>
    <p>Thanks for signing up. Please confirm that <strong>{{ $user->email }}</strong> is your email address.</p>
    @include('emails.partials.button', ['url' => $url, 'label' => 'Confirm email address'])
    <p>This link expires in 60 minutes. If you did not create an account, you can ignore this email.</p>
@endsection
