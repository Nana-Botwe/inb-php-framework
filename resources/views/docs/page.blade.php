@extends('docs.layout')

@section('title', $title)

@section('content')
    <article class="prose">
        {!! $html !!}
    </article>

    <nav class="pager" aria-label="Previous and next page">
        @if ($previous)
            <a class="prev" href="{{ route('docs.show', ['page' => $previous]) }}"><small>Previous</small>{{ $pages[$previous] }}</a>
        @else
            <span></span>
        @endif
        @if ($next)
            <a class="next" href="{{ route('docs.show', ['page' => $next]) }}"><small>Next</small>{{ $pages[$next] }}</a>
        @endif
    </nav>
@endsection

@if (count($toc) > 1)
    @section('toc')
        <ul>
            @foreach ($toc as $entry)
                @if ($entry['level'] <= 3)
                    <li @class(['sub' => $entry['level'] === 3])><a href="#{{ $entry['id'] }}">{{ $entry['text'] }}</a></li>
                @endif
            @endforeach
        </ul>
    @endsection
@endif
