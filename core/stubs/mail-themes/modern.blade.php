{{-- INB PHP Framework mail theme: MODERN — gradient header, rounded card, bold button. --}}
@php($brand = config('mail.brand_color', '#4f46e5'))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="color-scheme" content="light dark">
    <title>{{ $subject ?? config('app.name') }}</title>
    <style>
        @media (max-width: 620px) { .container { width: 100% !important; } .content { padding: 28px 22px !important; } }
        a.button:hover { opacity: .92; }
    </style>
</head>
<body style="margin:0;padding:0;background:#eef2ff;font-family:Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#1e293b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#eef2ff;padding:32px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;">
                <tr>
                    <td style="background:{{ $brand }};background-image:linear-gradient(135deg,{{ $brand }},#9333ea);border-radius:16px 16px 0 0;padding:28px 36px;">
                        <span style="font-size:20px;font-weight:700;color:#ffffff;letter-spacing:.3px;">{{ config('app.name') }}</span>
                    </td>
                </tr>
                <tr>
                    <td class="content" style="background:#ffffff;padding:40px 36px;border-radius:0 0 16px 16px;font-size:16px;line-height:1.65;box-shadow:0 10px 30px rgba(79,70,229,.08);">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td align="center" style="padding:24px 12px;font-size:12px;line-height:1.6;color:#64748b;">
                        &copy; {{ date('Y') }} {{ config('mail.company', config('app.name')) }}. All rights reserved.<br>
                        @yield('footer', 'You received this email because you have an account with us.')
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
