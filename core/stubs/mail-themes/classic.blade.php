{{-- INB PHP Framework mail theme: CLASSIC — corporate letter style with logo bar and serif headings. --}}
@php($brand = config('mail.brand_color', '#1d4ed8'))
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>{{ $subject ?? config('app.name') }}</title>
    <style>
        @media (max-width: 620px) { .container { width: 100% !important; } .content { padding: 24px 20px !important; } }
        h1, h2 { font-family: Georgia, 'Times New Roman', serif; }
    </style>
</head>
<body style="margin:0;padding:0;background:#f3f4f6;font-family:Arial,Helvetica,sans-serif;color:#111827;">
<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#f3f4f6;padding:28px 12px;">
    <tr>
        <td align="center">
            <table role="presentation" class="container" width="600" cellpadding="0" cellspacing="0" style="width:600px;max-width:600px;background:#ffffff;border:1px solid #d1d5db;">
                <tr>
                    <td style="border-top:5px solid {{ $brand }};padding:22px 32px;border-bottom:1px solid #e5e7eb;">
                        <span style="font-family:Georgia,serif;font-size:22px;color:{{ $brand }};">{{ config('app.name') }}</span>
                    </td>
                </tr>
                <tr>
                    <td class="content" style="padding:32px;font-size:15px;line-height:1.7;">
                        @yield('content')
                        <p style="margin-top:32px;">Kind regards,<br><strong>{{ config('mail.company', config('app.name')) }}</strong></p>
                    </td>
                </tr>
                <tr>
                    <td style="background:#f9fafb;border-top:1px solid #e5e7eb;padding:18px 32px;font-size:12px;line-height:1.6;color:#6b7280;">
                        {{ config('mail.company', config('app.name')) }} &middot; {{ config('mail.address', '') }}<br>
                        @yield('footer', 'This is an automated message, please do not reply directly.')
                    </td>
                </tr>
            </table>
        </td>
    </tr>
</table>            
</body>
</html>
