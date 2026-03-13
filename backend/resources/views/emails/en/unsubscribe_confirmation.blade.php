<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Unsubscribe Confirmation</title>
</head>
<body style="margin:0;padding:24px;background:#f6f4ef;font-family:Arial,sans-serif;color:#1f2933;line-height:1.6;">
<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;padding:32px;">
    <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;">You have been unsubscribed</h1>
    <p style="margin:0 0 16px;">This lifecycle confirmation records that your email subscription was turned off. It is a one-time system confirmation, not a marketing email.</p>

    <p style="margin:0 0 8px;"><strong>Current settings</strong></p>
    <ul style="margin:0 0 20px;padding-left:20px;">
        <li>Marketing updates: {{ !empty($marketing_updates) ? 'On' : 'Off' }}</li>
        <li>Report recovery: {{ !empty($report_recovery) ? 'On' : 'Off' }}</li>
        <li>Product updates: {{ !empty($product_updates) ? 'On' : 'Off' }}</li>
    </ul>

    <p style="margin:0 0 12px;"><a href="{{ $email_preferences_url }}">Manage email preferences</a></p>
    <p style="margin:0 0 12px;"><a href="{{ $email_unsubscribe_url }}">Unsubscribe from emails</a></p>
    <p style="margin:0 0 24px;"><a href="{{ $order_lookup_url }}">Order lookup</a></p>

    <p style="margin:0 0 8px;">You can restore settings later from the preferences page if needed.</p>
    <p style="margin:0 0 8px;">Need help? Contact <a href="mailto:{{ $support_email }}">{{ $support_email }}</a>.</p>

    <p style="margin:0;font-size:12px;color:#52606d;">
        <a href="{{ $privacy_url }}">Privacy</a> |
        <a href="{{ $terms_url }}">Terms</a> |
        <a href="{{ $refund_url }}">Refund</a>
    </p>
</div>
</body>
</html>
