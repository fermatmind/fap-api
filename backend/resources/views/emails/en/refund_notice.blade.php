<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Refund Notice</title>
</head>
<body style="font-family:Arial,sans-serif;color:#111;line-height:1.6;">
<h2>Refund Notice</h2>
<p>Your refund request is being processed.</p>
@if(!empty($orderNo))<p><strong>Order No:</strong> {{ $orderNo }}</p>@endif
@if(!empty($refundStatus))<p><strong>Status:</strong> {{ $refundStatus }}</p>@endif
@if(!empty($refundEta))<p><strong>Estimated arrival:</strong> {{ $refundEta }}</p>@endif
<p>If you need support, contact <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>.</p>
<p>
    <a href="{{ $privacyUrl }}">Privacy</a> |
    <a href="{{ $termsUrl }}">Terms</a> |
    <a href="{{ $refundUrl }}">Refund</a>
</p>
</body>
</html>
