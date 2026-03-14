<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>如何更好地使用你的 FermatMind 报告</title>
</head>
<body style="margin:0;padding:24px;background:#f6f4ef;font-family:Arial,sans-serif;color:#1f2933;line-height:1.7;">
<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;padding:32px;">
    <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;">如何更好地使用你的 FermatMind 报告</h1>
    <p style="margin:0 0 16px;">你已经打开过报告，之后随时都可以回到报告继续查看和使用。</p>
    <p style="margin:0 0 16px;">这封邮件只收口几个继续使用的入口：返回报告、需要时查询订单，以及遇到问题时获取帮助。</p>
    @if(!empty($order_no))
        <p style="margin:0 0 8px;"><strong>订单号：</strong>{{ $order_no }}</p>
    @endif

    @if(!empty($report_url))
        <p style="margin:0 0 12px;"><a href="{{ $report_url }}">返回报告</a></p>
    @endif
    <p style="margin:0 0 12px;"><a href="{{ $order_lookup_url }}">订单查询</a></p>
    <p style="margin:0 0 24px;"><a href="{{ $help_url }}">获取帮助</a></p>

    <p style="margin:0 0 8px;">如需帮助，请联系 <a href="mailto:{{ $support_email }}">{{ $support_email }}</a>。</p>
    <p style="margin:0 0 8px;"><a href="{{ $email_preferences_url }}">管理邮件偏好</a></p>
    <p style="margin:0 0 24px;"><a href="{{ $email_unsubscribe_url }}">退订邮件</a></p>

    <p style="margin:0;font-size:12px;color:#52606d;">
        <a href="{{ $privacy_url }}">隐私政策</a> |
        <a href="{{ $terms_url }}">服务条款</a> |
        <a href="{{ $refund_url }}">退款政策</a>
    </p>
</div>
</body>
</html>
