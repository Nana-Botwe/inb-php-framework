# Mail & email templates

## Drivers

```ini
MAIL_MAILER=log        # writes emails to storage/logs/mail.log (development)
MAIL_MAILER=smtp       # really sends
```

SMTP settings (Gmail with an app password, Mailtrap, cPanel mail, SendGrid SMTP...):

```ini
MAIL_HOST=smtp.gmail.com
MAIL_PORT=587
MAIL_ENCRYPTION=tls     # tls on 587, ssl on 465
MAIL_USERNAME=you@gmail.com
MAIL_PASSWORD=your-app-password
MAIL_FROM_ADDRESS=you@gmail.com
MAIL_FROM_NAME="INB IT Firm"
```

TLS certificates are verified. Addresses containing line breaks are rejected, so header injection is impossible.

Test it:

```bash
php console mail:test you@example.com
```

## The three email templates

The framework ships **three ready-made email designs**. Choose one with a single command:

| Template | Look | Best for |
|---|---|---|
| **modern** (default) | gradient header, rounded card, bold button | SaaS, apps, marketing |
| **classic** | corporate letter style, serif headings, sign-off and address footer | banks, schools, government, B2B |
| **minimal** | clean and text-first, very lightweight | best deliverability, transactional mail |

```bash
php console mail:theme modern
php console mail:theme classic
php console mail:theme minimal
```

The chosen template is copied to `resources/views/emails/layout.blade.php` (your previous one is kept as `.bak`) and `MAIL_THEME` is updated. Edit that file freely — it's yours. All templates are table-based with inline styles so they look right in Gmail, Outlook, Apple Mail and on phones.

Brand them in `.env`:

```ini
MAIL_BRAND_COLOR="#e11d48"
MAIL_COMPANY="INB IT Firm"
MAIL_COMPANY_ADDRESS="Accra, Ghana"
```

## Writing an email

```bash
php console make:mail InvoicePaid
```

This creates `app/Mail/InvoicePaid.php` and `resources/views/emails/invoice-paid.blade.php`.

```php
namespace App\Mail;

use App\Models\Invoice;
use Core\Mail\Mailable;

final class InvoicePaid extends Mailable
{
    public function __construct(private readonly Invoice $invoice) {}

    public function build(): void
    {
        $this->subject('Payment received — thank you')
            ->view('emails.invoice-paid', ['invoice' => $this->invoice])
            ->attach(storage_path('app/private/invoices/' . $this->invoice->id . '.pdf'), 'invoice.pdf');
    }
}
```

```blade
@extends('emails.layout')

@section('content')
    <h1 style="font-size:22px;margin:0 0 16px;">Thank you, {{ $invoice->customer_name }}!</h1>
    <p>We received <strong>{{ $invoice->amount() }}</strong>.</p>
    @include('emails.partials.button', ['url' => \Core\URL::to('/invoices/' . $invoice->id), 'label' => 'View invoice'])
@endsection

@section('footer', 'Questions? Reply to this email.')
```

> **Note:** Links in emails must be absolute: use `route('name', [...], true)` or `\Core\URL::to('/path')`. Set `APP_URL` in `.env`.

## Sending

```php
use Core\Mail\Mail;

Mail::to($user->email)->send(new InvoicePaid($invoice));
Mail::to(['a@example.com', 'b@example.com'])->cc('boss@example.com')->bcc('archive@example.com')->send($mail);
Mail::raw('ops@example.com', 'Disk almost full', 'Server disk is 91% full.');
```

A plain-text version is generated automatically from the HTML.

### Sending in the background

```php
Mail::to($user->email)->queue(new InvoicePaid($invoice));
```

`queue()` builds the message immediately (links and data are those of the current request) and then:

| `QUEUE_CONNECTION` | Delivery |
|---|---|
| `database` (recommended in production) | stored **encrypted** in the `jobs` table, sent by `php console queue:work`, retried 5 times |
| `sync` | sent **after the response** has been delivered. On PHP-FPM / LiteSpeed the visitor's connection is already closed; on Apache mod_php (XAMPP) the page is sent first but the connection stays open until the mail is done |

Either way, a slow or failing mail server never makes the page slower or shows an error — failures are logged and alerted. The framework uses this for password-reset emails, so mail-server timing or errors can't reveal which addresses have accounts.

`send()` still sends right away and throws if delivery fails — use it when the caller must know (e.g. `php console mail:test`).

## Built-in emails

| Email | View |
|---|---|
| Confirm your email | `emails/verify-email.blade.php` |
| Reset your password | `emails/reset-password.blade.php` |
| Welcome | `emails/welcome.blade.php` |
| Test | `emails/test.blade.php` |
