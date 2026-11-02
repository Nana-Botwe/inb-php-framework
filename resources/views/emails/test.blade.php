@extends('emails.layout')

@section('content')
    <h1 style="font-size:22px;margin:0 0 16px;">It works! 🎉</h1>
    <p>This is a test email from <strong>{{ config('app.name') }}</strong> using the <strong>{{ $theme }}</strong> template.</p>
    <p>Switch templates any time:</p>
    <p><code>php console mail:theme modern</code><br><code>php console mail:theme classic</code><br><code>php console mail:theme minimal</code></p>
    @include('emails.partials.button', ['url' => \Core\URL::to('/'), 'label' => 'Visit the site'])
@endsection
