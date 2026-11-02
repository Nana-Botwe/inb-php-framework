@extends('docs.layout')

@section('title', 'Search')

@section('content')
    <article class="prose">
        <h1>Search</h1>
        @if (mb_strlen($query) < 2)
            <p>Type at least two characters in the search box above.</p>
        @elseif (count($results) === 0)
            <p>No pages mention <strong>“{{ $query }}”</strong>. Try a shorter or different word.</p>
        @else
            <p>{{ count($results) }} {{ count($results) === 1 ? 'page mentions' : 'pages mention' }} <strong>“{{ $query }}”</strong>:</p>
            <ul class="results">
                @foreach ($results as $result)
                    <li>
                        <a href="{{ route('docs.show', ['page' => $result['slug']]) }}">{{ $result['title'] }}</a>
                        <p>…{{ $result['snippet'] }}…</p>
                    </li>
                @endforeach
            </ul>
        @endif
    </article>
@endsection
