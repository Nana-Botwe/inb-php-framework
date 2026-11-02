@extends('layouts.app')

@section('title', 'Payments')

@section('content')
    <h1 class="h3 mb-4">Payments</h1>

    <div class="row g-4">
        <div class="col-lg-5">
            <div class="card shadow-sm border-0">
                <div class="card-body p-4">
                    <h2 class="h5">Make a payment</h2>

                    @if ($gateway === '')
                        <div class="alert alert-warning mb-0">
                            No payment gateway is set up yet. Run
                            <code>php console payment:install paystack</code>
                            (or flutterwave, stripe, paypal, hubtel) and add your test keys to <code>.env</code>.
                        </div>
                    @else
                        <p class="text-body-secondary small">
                            You will be redirected to <strong class="text-capitalize">{{ $gateway }}</strong> to pay securely.
                            Card details never touch this server.
                        </p>

                        <form action="{{ route('payments.store') }}" method="post" novalidate>
                            @csrf
                            <div class="mb-3">
                                <label for="amount" class="form-label">Amount ({{ $currency }})</label>
                                <input type="text" inputmode="decimal" id="amount" name="amount" value="{{ old('amount') }}"
                                       @class(['form-control', 'is-invalid' => error('amount')]) placeholder="50.00" required>
                                @error('amount') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <div class="mb-4">
                                <label for="description" class="form-label">Description</label>
                                <input type="text" id="description" name="description" value="{{ old('description') }}" maxlength="150"
                                       @class(['form-control', 'is-invalid' => error('description')]) placeholder="Course fee">
                                @error('description') <div class="invalid-feedback">{{ $message }}</div> @enderror
                            </div>
                            <button type="submit" class="btn btn-primary w-100">Continue to payment</button>
                        </form>
                    @endif
                </div>
            </div>
        </div>

        <div class="col-lg-7">
            <div class="card shadow-sm border-0">
                <div class="card-header bg-transparent fw-semibold">Your payments</div>
                <div class="card-body p-0">
                    @include('payments.table', ['payments' => $payments])
                </div>
            </div>
        </div>
    </div>
@endsection
