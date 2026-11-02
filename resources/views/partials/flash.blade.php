{{-- One-time messages set with ->with('success', '...') on a redirect. --}}
@foreach (['success' => 'success', 'warning' => 'warning', 'error' => 'danger', 'info' => 'info'] as $key => $class)
    @session($key)
        <div class="alert alert-{{ $class }} alert-dismissible fade show" role="alert">
            {{ $value }}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    @endsession
@endforeach
