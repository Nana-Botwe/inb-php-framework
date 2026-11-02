# Queues

Slow work (sending emails, generating PDFs, calling APIs) can run in the background so pages stay fast.

## Drivers

```ini
QUEUE_CONNECTION=sync       # run immediately (default, no setup)
QUEUE_CONNECTION=database   # store in the jobs table, processed by a worker
```

## Writing a job

```bash
php console make:job GenerateInvoicePdf
```

```php
namespace App\Jobs;

use Core\Queue\Job;

final class GenerateInvoicePdf extends Job
{
    public int $tries = 3;        // attempts before giving up
    public int $backoff = 30;     // seconds between attempts

    public function __construct(public readonly int $invoiceId) {}

    public function handle(InvoiceService $invoices): void   // dependencies are injected
    {
        $invoices->renderPdf($this->invoiceId);
    }

    public function failed(\Throwable $e): void
    {
        logger('invoice_pdf_failed', ['invoice' => $this->invoiceId]);
    }
}
```

> **Tip:** Pass IDs, not whole models — the data may have changed by the time the job runs, and objects are rejected (see [Payload safety](#payload-safety)).

## Dispatching

```php
dispatch(new GenerateInvoicePdf($invoice->id));
dispatch((new GenerateInvoicePdf($invoice->id))->delay(60));      // in 1 minute
dispatch((new SendNewsletter())->onQueue('emails'));
```

## Running the worker

```bash
php console queue:work                    # keeps running
php console queue:work --queue=emails
php console queue:work --once             # one job, then exit
php console queue:failed                  # list failures
php console queue:retry 5                 # retry one
php console queue:retry all
php console queue:flush                   # delete all failed jobs
```

Jobs are claimed atomically, so several workers can run side by side. A job that keeps failing is moved to `failed_jobs` with the error.

On a server, keep the worker running with Supervisor/systemd (Linux) or a Windows scheduled task, or run `queue:work --stop-when-empty` from the [scheduler](scheduling) every minute.

## Payload safety

Jobs are stored as plain JSON — the job's class name plus its properties (`{"job": "App\\Jobs\\SendInvoice", "data": {"invoiceId": 5}}`) — never with PHP `serialize()`. Restoring a job can't construct arbitrary objects, and only classes allowed in `config/queue.php` (default: everything in `App\Jobs`) can run; anything else goes straight to `failed_jobs`.

Because of this, job properties must be scalars, `null` or arrays of those. Passing an object (like a model) throws immediately — even with `QUEUE_CONNECTION=sync` — so the mistake shows up during development.
