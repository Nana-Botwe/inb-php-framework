@extends('layouts.app')

@section('title', 'Users')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <h1 class="h3 mb-0">Users <span class="badge text-bg-secondary align-middle">{{ $users->total() }}</span></h1>
        <form method="get" action="{{ route('admin.users.index') }}" class="d-flex gap-2" role="search">
            <input type="search" name="q" value="{{ $search }}" class="form-control" placeholder="Search name or email" maxlength="100">
            <button class="btn btn-outline-secondary" type="submit">Search</button>
        </form>
    </div>

    <div class="card shadow-sm border-0">
        <div class="table-responsive">
            <table class="table table-hover align-middle mb-0">
                <thead class="table-light"><tr><th>#</th><th>Name</th><th>Email</th><th>Verified</th><th>Role</th><th>Joined</th></tr></thead>
                <tbody>
                    @forelse ($users as $user)
                        <tr>
                            <td class="text-body-secondary">{{ $user->id }}</td>
                            <td class="fw-semibold">{{ $user->name }}</td>
                            <td>{{ $user->email }}</td>
                            <td>{!! $user->hasVerifiedEmail() ? '<span class="badge text-bg-success">Yes</span>' : '<span class="badge text-bg-warning">No</span>' !!}</td>
                            <td>
                                <form action="{{ route('admin.users.role', ['user' => $user->id]) }}" method="post" class="d-flex gap-1">
                                    @csrf
                                    @method('PATCH')
                                    <select name="role" class="form-select form-select-sm" aria-label="Role for {{ $user->name }}" @disabled((int) $user->id === (int) auth()->id)>
                                        @foreach ($roles as $role)
                                            <option value="{{ $role }}" @selected($user->role === $role)>{{ ucfirst($role) }}</option>
                                        @endforeach
                                    </select>
                                    <button class="btn btn-sm btn-outline-primary" type="submit" @disabled((int) $user->id === (int) auth()->id)>Save</button>
                                </form>
                            </td>
                            <td class="small text-body-secondary">{{ date('j M Y', strtotime((string) $user->created_at)) }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="6" class="text-center text-body-secondary py-4">No users found.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div class="mt-3">{{ $users->links() }}</div>
@endsection
