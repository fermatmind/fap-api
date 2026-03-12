<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>退款处理通知</title>
</head>
<body style="margin:0;padding:24px;background:#f6f4ef;font-family:Arial,sans-serif;color:#1f2933;line-height:1.7;">
<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;padding:32px;">
    <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;">退款通知</h1>
    <p style="margin:0 0 16px;">您的退款申请正在处理。</p>
    @if(!empty($order_no))
        <p style="margin:0 0 8px;"><strong>订单号：</strong>{{ $order_no }}</p>
    @endif
    @if(!empty($refund_status))
        <p style="margin:0 0 8px;"><strong>当前状态：</strong>{{ $refund_status }}</p>
    @endif
    @if(!empty($refund_eta))
        <p style="margin:0 0 20px;"><strong>预计到账：</strong>{{ $refund_eta }}</p>
    @endif

    <p style="margin:0 0 12px;"><a href="{{ $order_lookup_url }}">订单查询</a></p>
    <p style="margin:0 0 8px;">如需支持，请联系 <a href="mailto:{{ $support_email }}">{{ $support_email }}</a>。</p>
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
