<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Support Contact</title>
</head>
<body style="font-family:Arial,sans-serif;color:#111;line-height:1.6;">
<h2>Support Contact</h2>
<p>We received your support request.</p>
@if(!empty($orderNo))<p><strong>Order No:</strong> {{ $orderNo }}</p>@endif
@if(!empty($reportUrl))
<p><a href="{{ $reportUrl }}">Open report link</a></p>
@endif
<p>Email: <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></p>
@if(!empty($supportTicketUrl))
<p>Ticket: <a href="{{ $supportTicketUrl }}">{{ $supportTicketUrl }}</a></p>
@endif
<p>
    <a href="{{ $privacyUrl }}">Privacy</a> |
    <a href="{{ $termsUrl }}">Terms</a> |
    <a href="{{ $refundUrl }}">Refund</a>
</p>
</body>
</html>
