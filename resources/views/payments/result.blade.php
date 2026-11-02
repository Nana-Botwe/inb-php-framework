@extends('layouts.app')

@section('title', 'Payment status')

@section('content')
    <div class="row justify-content-center">
        <div class="col-md-8 col-lg-6">
            <div class="card shadow-sm border-0 text-center">
                <div class="card-body p-5">
                    @if ($payment->isPaid())
                        <div class="display-5 text-success mb-2">✓</div>
                        <h1 class="h4">Payment successful</h1>
                        <p class="text-body-secondary">Thank you! We received <strong>{{ $payment->amount() }}</strong>.</p>
                    @elseif ($payment->status === 'failed')
                        <div class="display-5 text-danger mb-2">✕</div>
                        <h1 class="h4">Payment failed</h1>
                        <p class="text-body-secondary">The payment was not completed. You have not been charged.</p>
                    @else
                        <div class="display-5 text-warning mb-2">…</div>
                        <h1 class="h4">Payment pending</h1>
                        <p class="text-body-secondary">We are waiting for confirmation from {{ ucfirst((string) $payment->gateway) }}. This page will be updated once it arrives.</p>
                    @endif

                    <p class="small mb-4">Reference: <code>{{ $payment->reference }}</code></p>
                    <a href="{{ route('payments.create') }}" class="btn btn-primary">Back to payments</a>
                </div>
            </div>
        </div>
    </div>
@endsection
