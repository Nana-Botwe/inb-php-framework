@extends('layouts.app')

@section('title', 'Dashboard')

@section('content')
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-4">
        <div>
            <h1 class="h3 mb-0">Hello, {{ $user->name }} 👋</h1>
            <p class="text-body-secondary mb-0">Here is what is happening with your account.</p>
        </div>
        <a href="{{ route('payments.create') }}" class="btn btn-primary">Make a payment</a>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="text-body-secondary small">Email</div>
                    <div class="fw-semibold text-truncate">{{ $user->email }}</div>
                    <span class="badge text-bg-success mt-2">Verified</span>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="text-body-secondary small">Role</div>
                    <div class="fw-semibold text-capitalize">{{ $user->role }}</div>
                </div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="card h-100 shadow-sm border-0">
                <div class="card-body">
                    <div class="text-body-secondary small">Member since</div>
                    <div class="fw-semibold">{{ date('j M Y', strtotime((string) $user->created_at)) }}</div>
                </div>
            </div>
        </div>
    </div>

    <div class="card shadow-sm border-0">
        <div class="card-header bg-transparent fw-semibold">Recent payments</div>
        <div class="card-body p-0">
            @include('payments.table', ['payments' => $payments])
        </div>
    </div>
@endsection
