<div class="table-responsive">
    <table class="table table-hover align-middle mb-0">
        <thead class="table-light">
            <tr><th>Reference</th><th>Description</th><th>Amount</th><th>Gateway</th><th>Status</th><th>Date</th></tr>
        </thead>
        <tbody>
            @forelse ($payments ?? [] as $payment)
                <tr>
                    <td><code class="small">{{ $payment->reference }}</code></td>
                    <td>{{ $payment->description }}</td>
                    <td class="fw-semibold">{{ $payment->amount() }}</td>
                    <td class="text-capitalize">{{ $payment->gateway }}</td>
                    <td>
                        <span @class(['badge', 'text-bg-success' => $payment->status === 'success', 'text-bg-warning' => $payment->status === 'pending', 'text-bg-danger' => $payment->status === 'failed'])>
                            {{ ucfirst((string) $payment->status) }}
                        </span>
                    </td>
                    <td class="small text-body-secondary">{{ $payment->created_at }}</td>
                </tr>
            @empty
                <tr><td colspan="6" class="text-center text-body-secondary py-4">No payments yet.</td></tr>
            @endforelse
        </tbody>
    </table>
</div>
