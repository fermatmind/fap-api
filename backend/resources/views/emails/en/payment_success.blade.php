<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Payment Successful</title>
</head>
<body style="font-family:Arial,sans-serif;color:#111;line-height:1.6;">
<h2>Payment Successful</h2>
<p>Your payment has been confirmed and your report delivery is ready.</p>
@if(!empty($orderNo))<p><strong>Order No:</strong> {{ $orderNo }}</p>@endif
@if(!empty($productSummary))<p><strong>Purchase:</strong> {{ $productSummary }}</p>@endif
@if(!empty($reportUrl))
<p><a href="{{ $reportUrl }}">View your report</a></p>
@endif
<p>If you need help, contact <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
<p>
    <a href="{{ $privacyUrl }}">Privacy</a> |
    <a href="{{ $termsUrl }}">Terms</a> |
    <a href="{{ $refundUrl }}">Refund</a>
</p>
</body>
</html>
