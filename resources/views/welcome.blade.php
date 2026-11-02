@extends('layouts.site')

@section('content')
    <section class="hero">
        <div class="logo">@include('partials.logo', ['size' => 92])</div>
        <h1>Build fast. Ship <span>secure.</span></h1>
        <p class="lead">
            Welcome to <strong>{{ config('app.name') }}</strong> — the INB PHP Framework gives every project
            routing, Blade views, an ORM for every major database, authentication, payments, SMS and enterprise-grade security out of the box.
        </p>
        <div class="chips">
            <span class="chip">Firewall &amp; CSP</span>
            <span class="chip">Encrypted tokens</span>
            <span class="chip">5 payment gateways</span>
            <span class="chip">4 SMS gateways</span>
            <span class="chip">8 database drivers</span>
            <span class="chip">Auto-compressed uploads</span>
        </div>
    </section>

    <section class="grid" aria-label="Features">
        <a class="card" href="{{ route('docs.index') }}">
            <div class="icon" aria-hidden="true">
                <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M4 19.5A2.5 2.5 0 0 1 6.5 17H20"/><path d="M6.5 2H20v20H6.5A2.5 2.5 0 0 1 4 19.5v-15A2.5 2.5 0 0 1 6.5 2z"/></svg>
            </div>
            <h2>Documentation</h2>
            <p>35 guides covering everything: routing, Blade, the ORM, security, payments, SMS, deployment and more.</p>
            <span class="more">Read the docs <span aria-hidden="true">→</span></span>
        </a>

        @foreach ($features as $slug => $feature)
            <a class="card" href="{{ route('features.show', ['feature' => $slug]) }}">
                <div class="icon" aria-hidden="true">@include('features.icon', ['name' => $feature['icon']])</div>
                <h2>{{ $feature['card_title'] }}</h2>
                <p>{{ $feature['card_text'] }}</p>
                <span class="more">See full details <span aria-hidden="true">→</span></span>
            </a>
        @endforeach
    </section>

    <section class="start">
        <h2>Get started in a minute</h2>
        <ol>
            <li>Set your database in <code>.env</code> (MySQL, MariaDB, PostgreSQL, SQLite, SQL Server, Oracle…), then run <code>php console migrate --seed</code></li>
            <li>Add a page: a route in <code>routes/web.php</code> and a view in <code>resources/views</code></li>
            <li>Generate code: <code>php console make:controller PostController --resource</code></li>
            <li>Pick your email look: <code>php console mail:theme modern</code></li>
        </ol>
    </section>
@endsection
