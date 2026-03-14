<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>欢迎来到 FermatMind</title>
</head>
<body style="margin:0;padding:24px;background:#f6f4ef;font-family:Arial,sans-serif;color:#1f2933;line-height:1.7;">
<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;padding:32px;">
    <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;">欢迎来到 FermatMind</h1>
    <p style="margin:0 0 16px;">这是一封一次性的欢迎邮件，用来确认 FermatMind 之后可以在需要时通过这个邮箱联系你。</p>
    <p style="margin:0 0 16px;">你可以随时管理邮件偏好、停止接收邮件；如果以后需要恢复报告，也可以通过订单查询找回。</p>

    <p style="margin:0 0 12px;"><a href="{{ $email_preferences_url }}">管理邮件偏好</a></p>
    <p style="margin:0 0 12px;"><a href="{{ $email_unsubscribe_url }}">退订邮件</a></p>
    <p style="margin:0 0 12px;"><a href="{{ $order_lookup_url }}">订单查询</a></p>
    <p style="margin:0 0 24px;"><a href="{{ $help_url }}">查看帮助中心</a></p>

    <p style="margin:0 0 8px;">需要帮助？请联系 <a href="mailto:{{ $support_email }}">{{ $support_email }}</a>。</p>

    <p style="margin:0;font-size:12px;color:#52606d;">
        <a href="{{ $privacy_url }}">隐私政策</a> |
        <a href="{{ $terms_url }}">服务条款</a> |
        <a href="{{ $refund_url }}">退款政策</a>
    </p>
</div>
</body>
</html>
