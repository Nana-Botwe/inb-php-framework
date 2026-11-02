@extends('layouts.site')

@section('title', $feature['card_title'])
@section('description', $feature['card_text'])

@php($md = static fn (string $text) => \Core\Support\Markdown::inlineText($text))

@section('content')
    <nav class="breadcrumb" aria-label="Breadcrumb">
        <a href="{{ route('home') }}">Home</a> <span aria-hidden="true">/</span> Features <span aria-hidden="true">/</span> {{ $feature['card_title'] }}
    </nav>

    <section class="feature-hero">
        <div class="eyebrow"><span class="icon" aria-hidden="true">@include('features.icon', ['name' => $feature['icon']])</span>{{ $feature['eyebrow'] }}</div>
        <h1>{{ $feature['title'] }}</h1>
        <p class="lead">{{ $md($feature['lead']) }}</p>

        <div class="stats">
            @foreach ($feature['stats'] as [$value, $label])
                <div class="stat"><strong class="grad">{{ $value }}</strong><span>{{ $label }}</span></div>
            @endforeach
        </div>

        <nav class="jump" aria-label="On this page">
            @foreach ($feature['sections'] as $section)
                <a href="#{{ $section['id'] }}">{{ $section['title'] }}</a>
            @endforeach
        </nav>
    </section>

    @foreach ($feature['sections'] as $section)
        <section class="section" id="{{ $section['id'] }}" aria-labelledby="{{ $section['id'] }}-title">
            <div class="section-head">
                <span class="icon" aria-hidden="true">@include('features.icon', ['name' => $section['icon'] ?? 'check'])</span>
                <div>
                    <h2 id="{{ $section['id'] }}-title">{{ $section['title'] }}</h2>
                    @isset($section['text'])
                        <p>{{ $md($section['text']) }}</p>
                    @endisset
                </div>
            </div>

            @isset($section['points'])
                <ul class="points">
                    @foreach ($section['points'] as $point)
                        <li>{{ $md($point) }}</li>
                    @endforeach
                </ul>
            @endisset

            @isset($section['steps'])
                <ol class="steps">
                    @foreach ($section['steps'] as $step)
                        <li>{{ $md($step) }}</li>
                    @endforeach
                </ol>
            @endisset

            @isset($section['table'])
                <div class="table-wrap">
                    <table>
                        <thead><tr>@foreach ($section['table']['head'] as $head)<th>{{ $head }}</th>@endforeach</tr></thead>
                        <tbody>
                            @foreach ($section['table']['rows'] as $row)
                                <tr>@foreach ($row as $cell)<td>{{ $md($cell) }}</td>@endforeach</tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            @endisset

            @isset($section['code'])
                <div class="code-block">
                    <div class="code-lang">{{ $section['code'][0] }}</div>
                    <pre><code class="language-{{ $section['code'][0] }}">{{ $section['code'][1] }}</code></pre>
                </div>
            @endisset
        </section>
    @endforeach

    <section class="cta">
        <h2>Read the full guides</h2>
        <p>Step-by-step documentation with examples for everything on this page.</p>
        <div class="actions">
            @foreach ($feature['docs'] as $page => $label)
                <a class="btn {{ $loop->first ? 'primary' : '' }}" href="{{ route('docs.show', ['page' => $page]) }}">{{ $label }}</a>
            @endforeach
        </div>
    </section>

    <section class="other" aria-label="Other features">
        @foreach ($others as $otherSlug => $other)
            <a class="card" href="{{ route('features.show', ['feature' => $otherSlug]) }}">
                <div class="icon" aria-hidden="true">@include('features.icon', ['name' => $other['icon']])</div>
                <h2>{{ $other['card_title'] }}</h2>
                <p>{{ $other['card_text'] }}</p>
                <span class="more">See full details <span aria-hidden="true">→</span></span>
            </a>
        @endforeach
    </section>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/docs.js') }}" defer></script>
@endpush
