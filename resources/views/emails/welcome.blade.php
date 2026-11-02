@extends('emails.layout')

@section('content')
    <h1 style="font-size:22px;margin:0 0 16px;">Welcome, {{ $user->name }}!</h1>
    <p>Your account at <strong>{{ config('app.name') }}</strong> is ready.</p>
    @include('emails.partials.button', ['url' => $url, 'label' => 'Open your dashboard'])
    <p>If you have any questions, just reply to this email.</p>
@endsection
