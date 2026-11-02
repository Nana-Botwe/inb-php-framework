{{-- INB PHP Framework mail theme: MINIMAL — clean, text-first, lightweight (great deliverability). --}}
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? config('app.name') }}</title>
    <style>
        @media (max-width: 620px) { .container { width: 100% !important; } }
    </style>
</head>
<body style="margin:0;padding:0;background:#ffffff;font-family:-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif;color:#18181b;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="padding:40px 16px;">
    <tr>
        <td align="center">
            <table role="presentation" class="container" width="560" cellpadding="0" cellspacing="0" style="width:560px;max-width:560px;">
                <tr>
                    <td style="padding-bottom:28px;font-size:15px;font-weight:600;color:#71717a;">{{ config('app.name') }}</td>
                </tr>
                <tr>
                    <td style="font-size:16px;line-height:1.7;">
                        @yield('content')
                    </td>
                </tr>
                <tr>
                    <td style="padding-top:36px;margin-top:36px;border-top:1px solid #e4e4e7;font-size:12px;line-height:1.6;color:#a1a1aa;">
                        &copy; {{ date('Y') }} {{ config('mail.company', config('app.name')) }}<br>
                        @yield('footer', '')
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>
</body>
</html>
