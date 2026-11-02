# Views & Blade

Views live in `resources/views` and use **Blade** syntax (files ending in `.blade.php`). Plain `.php` views work too. Templates are compiled to PHP once and cached in `storage/framework/views` until you change them.

```php
return view('posts.show', ['post' => $post]);   // resources/views/posts/show.blade.php
```

Dots are folders: `admin.users` = `admin/users.blade.php`.

## Displaying data

```blade
<h1>{{ $post->title }}</h1>            {{-- escaped: safe against XSS --}}
{!! $trustedHtml !!}                   {{-- raw: ONLY for HTML you created yourself --}}
@{{ this is printed as-is }}           {{-- for Vue/Alpine templates --}}
```

> **Security:** `{{ }}` escapes everything, so a user who names themselves `<script>` can't attack other users. Use `{!! !!}` only for HTML that never contains user input.

## Layouts

```blade
{{-- resources/views/layouts/app.blade.php --}}
<title>@yield('title', 'My site')</title>
<main>@yield('content')</main>
@stack('scripts')
```

```blade
{{-- resources/views/posts/show.blade.php --}}
@extends('layouts.app')

@section('title', $post->title)

@section('content')
    <h1>{{ $post->title }}</h1>
@endsection

@push('scripts')
    <script src="{{ asset('assets/js/post.js') }}"></script>
@endpush
```

The framework ships `layouts.app` (signed-in area with navigation) and `layouts.guest` (centred card for login pages).

## Control structures

```blade
@if ($user->isAdmin())
    Admin
@elseif ($user->hasRole('editor'))
    Editor
@else
    User
@endif

@unless ($post->published) Draft @endunless
@isset($discount) {{ $discount }}% off @endisset
@empty($items) Nothing here @endempty

@switch($status)
    @case('paid') Paid @break
    @default Pending
@endswitch
```

## Loops

```blade
@foreach ($posts as $post)
    <li @class(['first' => $loop->first])>{{ $loop->iteration }}. {{ $post->title }}</li>
@endforeach

@forelse ($posts as $post)
    <li>{{ $post->title }}</li>
@empty
    <li>No posts yet.</li>
@endforelse

@for ($i = 1; $i <= 3; $i++) {{ $i }} @endfor
@while ($item = array_shift($queue)) {{ $item }} @endwhile
```

`$loop` gives you `index`, `iteration`, `count`, `remaining`, `first`, `last`, `even`, `odd`, `depth` and `parent`.

## Including partials

```blade
@include('partials.nav')
@include('partials.alert', ['type' => 'danger', 'text' => 'Oops'])
@includeIf('partials.promo')
@includeWhen($user->isAdmin(), 'partials.admin-bar')
```

## Components

```blade
{{-- resources/views/components/card.blade.php --}}
<div class="card"><div class="card-header">{{ $title }}</div><div class="card-body">{{ $slot }}</div></div>

@component('components.card', ['title' => 'Stats'])
    <p>Content goes into $slot.</p>
@endcomponent
```

## Forms

```blade
<form method="post" action="{{ route('posts.update', ['post' => $post]) }}">
    @csrf
    @method('PUT')

    <input name="title" value="{{ old('title', $post->title) }}" @class(['form-control', 'is-invalid' => error('title')])>
    @error('title') <div class="invalid-feedback">{{ $message }}</div> @enderror

    <input type="checkbox" name="featured" value="1" @checked(old('featured', $post->featured))>
    <select name="status">
        <option value="draft" @selected($post->status === 'draft')>Draft</option>
    </select>
    <button @disabled($locked)>Save</button>
</form>
```

- `@csrf` — required in every POST form (masked token, see [Security](security#csrf-protection)).
- `@method('PUT')` — send PUT/PATCH/DELETE from a form.
- `old('field')` — what the user typed before a validation error.
- `@error('field') ... @enderror` — shows `$message` when that field has an error.

## Auth & environment

```blade
@auth Welcome back, {{ auth()->name }} @endauth
@guest <a href="{{ route('login') }}">Log in</a> @endguest
@role('admin') <a href="{{ route('admin.users.index') }}">Admin</a> @endrole
@env('local') Development build @endenv
@production <script src="{{ asset('assets/js/analytics.js') }}"></script> @endproduction
@session('success') <div class="alert">{{ $value }}</div> @endsession
```

## Content-Security-Policy and inline code

Inline `<script>` and `<style>` are blocked by the CSP unless they carry the per-request nonce. Inline `style="..."` attributes are blocked too — put styles in `public/assets/css`.

```blade
<script nonce="@nonce">console.log('allowed');</script>
<script>const data = @json($chartData);</script>   {{-- @json is safe inside <script> --}}
```

## Other directives

| Directive | Purpose |
|---|---|
| `@php ... @endphp`, `@php($x = 1)` | run PHP |
| `@json($data)` | safe JSON for JavaScript |
| `@lang('auth.failed')` | translation |
| `@verbatim ... @endverbatim` | output without compiling |
| `@dump($x)` / `@dd($x)` | debugging |
| `{{-- comment --}}` | comment (not sent to the browser) |

## Sharing data with every view

```php
// app/Providers/AppServiceProvider.php
View::share('company', 'INB IT Firm');
View::composer('layouts.*', fn () => ['menu' => Menu::all()]);
```

Clear compiled views with `php console view:clear`.
