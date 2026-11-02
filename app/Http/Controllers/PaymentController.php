<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\Payment as PaymentRecord;
use App\Services\PaymentService;
use Core\Auth;
use Core\HttpException;
use Core\Payments\PaymentException;
use Core\Request;
use Core\Response;

/**
 * HTTP layer for payments. All state changes and security decisions live in App\Services\PaymentService.
 */
final class PaymentController extends Controller
{
    public function __construct(private readonly PaymentService $payments)
    {
    }

    public function create(): Response
    {
        return $this->view('payments.checkout', [
            'gateway' => (string) config('payments.default'),
            'currency' => (string) config('payments.currency', 'GHS'),
            'payments' => PaymentRecord::where('user_id', Auth::id())->latest()->limit(10)->get(),
        ]);
    }

    public function store(Request $request): Response
    {
        $data = $request->validate([
            'amount' => ['required', 'regex:/^\d{1,9}(\.\d{1,2})?$/'],
            'description' => 'nullable|string|max:150|no_html',
        ]);

        try {
            $checkout = $this->payments->start(Auth::user(), (string) $data['amount'], isset($data['description']) ? (string) $data['description'] : null);
        } catch (PaymentException $e) {
            report($e);

            return $this->back()->withErrors(['amount' => $e->getMessage()])->withInput($request->only(['amount', 'description']));
        }

        return Response::redirect($checkout->url);
    }

    /** The customer is sent back here by the provider. The redirect itself proves nothing: we verify. */
    public function callback(Request $request, string $gateway): Response
    {
        $reference = (string) ($request->query('reference') ?? $request->query('trxref') ?? $request->query('tx_ref')
            ?? $request->query('session_id') ?? $request->query('token') ?? $request->query('clientReference') ?? '');

        $record = $reference === '' ? null : PaymentRecord::where('gateway', $gateway)
            ->where(fn ($q) => $q->where('reference', $reference)->orWhere('gateway_reference', $reference))
            ->where('user_id', Auth::id())
            ->first();

        if ($record === null) {
            throw new HttpException(404, 'Payment not found.');
        }

        // If the provider is unreachable right now, the page shows "pending" and the
        // scheduled reconciliation finishes the job.
        $this->payments->confirm($record);

        return $this->view('payments.result', ['payment' => $record->refresh()]);
    }

    /** Server-to-server notification. CSRF is skipped for this route; the SIGNATURE is checked instead. */
    public function webhook(Request $request, string $gateway): Response
    {
        if (!array_key_exists($gateway, (array) config('payments.gateways', []))) {
            throw new HttpException(404);
        }

        $rawBody = (string) file_get_contents('php://input');
        if ($rawBody === '' && $request->except(['_token']) !== []) {
            $rawBody = (string) json_encode($request->except(['_token']), JSON_UNESCAPED_SLASHES); // tests / CLI
        }

        return match ($this->payments->handleWebhook($gateway, $request, $rawBody)) {
            'invalid' => Response::json(['message' => 'Invalid signature'], 400),
            // Temporary failure: a non-2xx status makes the provider retry; the event is also stored.
            'retry' => Response::json(['message' => 'Temporarily unavailable, please retry'], 503)->header('Retry-After', '60'),
            default => Response::json(['received' => true]),
        };
    }
}
