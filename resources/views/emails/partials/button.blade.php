{{-- Bulletproof email button (works in Outlook, Gmail, Apple Mail). Usage: @include('emails.partials.button', ['url' => $url, 'label' => 'Confirm']) --}}
<table role="presentation" cellpadding="0" cellspacing="0" style="margin:28px 0;">
    <tr>
        <td style="border-radius:10px;background:{{ config('mail.brand_color', '#4f46e5') }};">
            <a href="{{ $url }}" target="_blank" rel="noopener"
               style="display:inline-block;padding:13px 26px;font-size:15px;font-weight:600;color:#ffffff;text-decoration:none;border-radius:10px;">
                {{ $label }}
            </a>
        </td>
    </tr>
</table>
<p style="font-size:12px;color:#64748b;word-break:break-all;">If the button doesn't work, copy this link into your browser:<br>{{ $url }}</p>
