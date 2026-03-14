<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>How to get more from your FermatMind report</title>
</head>
<body style="margin:0;padding:24px;background:#f6f4ef;font-family:Arial,sans-serif;color:#1f2933;line-height:1.6;">
<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;padding:32px;">
    <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;">How to get more from your FermatMind report</h1>
    <p style="margin:0 0 16px;">You already opened your report, and you can come back to keep using it whenever you are ready.</p>
    <p style="margin:0 0 16px;">This email is a short reminder of the key paths: return to the report, look up your order if needed, and reach help when you want support.</p>
    @if(!empty($order_no))
        <p style="margin:0 0 8px;"><strong>Order No:</strong> {{ $order_no }}</p>
    @endif

    @if(!empty($report_url))
        <p style="margin:0 0 12px;"><a href="{{ $report_url }}">Return to your report</a></p>
    @endif
    <p style="margin:0 0 12px;"><a href="{{ $order_lookup_url }}">Order lookup</a></p>
    <p style="margin:0 0 24px;"><a href="{{ $help_url }}">Get help</a></p>

    <p style="margin:0 0 8px;">Need help? Contact <a href="mailto:{{ $support_email }}">{{ $support_email }}</a>.</p>
    <p style="margin:0 0 8px;"><a href="{{ $email_preferences_url }}">Manage email preferences</a></p>
    <p style="margin:0 0 24px;"><a href="{{ $email_unsubscribe_url }}">Unsubscribe from emails</a></p>

    <p style="margin:0;font-size:12px;color:#52606d;">
        <a href="{{ $privacy_url }}">Privacy</a> |
        <a href="{{ $terms_url }}">Terms</a> |
        <a href="{{ $refund_url }}">Refund</a>
    </p>
</div>
</body>
</html>
