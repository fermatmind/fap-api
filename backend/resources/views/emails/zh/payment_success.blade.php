<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>支付成功通知</title>
</head>
<body style="font-family:Arial,sans-serif;color:#111;line-height:1.7;">
<h2>支付成功</h2>
<p>您的支付已确认，报告已可查看。</p>
@if(!empty($orderNo))<p><strong>订单号：</strong>{{ $orderNo }}</p>@endif
@if(!empty($productSummary))<p><strong>购买内容：</strong>{{ $productSummary }}</p>@endif
@if(!empty($reportUrl))
<p><a href="{{ $reportUrl }}">查看报告</a></p>
@endif
<p>如需帮助，请联系 <a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a>。</p>
<p>
    <a href="{{ $privacyUrl }}">隐私政策</a> |
    <a href="{{ $termsUrl }}">服务条款</a> |
    <a href="{{ $refundUrl }}">退款政策</a>
</p>
</body>
</html>
