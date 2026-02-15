<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>退款处理通知</title>
</head>
<body style="font-family:Arial,sans-serif;color:#111;line-height:1.7;">
<h2>退款通知</h2>
<p>您的退款申请正在处理。</p>
@if(!empty($orderNo))<p><strong>订单号：</strong>{{ $orderNo }}</p>@endif
@if(!empty($refundStatus))<p><strong>当前状态：</strong>{{ $refundStatus }}</p>@endif
@if(!empty($refundEta))<p><strong>预计到账：</strong>{{ $refundEta }}</p>@endif
<p>如需支持，请联系 <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>。</p>
<p>
    <a href="{{ $privacyUrl }}">隐私政策</a> |
    <a href="{{ $termsUrl }}">服务条款</a> |
    <a href="{{ $refundUrl }}">退款政策</a>
</p>
</body>
</html>
