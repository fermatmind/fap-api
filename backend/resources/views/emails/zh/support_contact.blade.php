<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>客服联系方式</title>
</head>
<body style="font-family:Arial,sans-serif;color:#111;line-height:1.7;">
<h2>客服支持</h2>
<p>我们已收到您的支持请求。</p>
@if(!empty($orderNo))<p><strong>订单号：</strong>{{ $orderNo }}</p>@endif
@if(!empty($reportUrl))
<p><a href="{{ $reportUrl }}">打开报告链接</a></p>
@endif
<p>邮箱：<a href="mailto:{{ $supportEmail }}">{{ $supportEmail }}</a></p>
@if(!empty($supportTicketUrl))
<p>工单：<a href="{{ $supportTicketUrl }}">{{ $supportTicketUrl }}</a></p>
@endif
<p>
    <a href="{{ $privacyUrl }}">隐私政策</a> |
    <a href="{{ $termsUrl }}">服务条款</a> |
    <a href="{{ $refundUrl }}">退款政策</a>
</p>
</body>
</html>
