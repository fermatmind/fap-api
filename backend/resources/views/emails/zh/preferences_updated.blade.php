<!doctype html>
<html lang="zh-CN">
<head>
    <meta charset="utf-8">
    <title>邮件偏好已更新</title>
</head>
<body style="margin:0;padding:24px;background:#f6f4ef;font-family:Arial,sans-serif;color:#1f2933;line-height:1.7;">
<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;padding:32px;">
    <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;">邮件偏好已更新</h1>
    <p style="margin:0 0 16px;">这是一封 lifecycle confirmation，用于确认你的 FermatMind 邮件设置发生了变更。它不是 newsletter，也不是 campaign 邮件。</p>

    <p style="margin:0 0 8px;"><strong>当前设置</strong></p>
    <ul style="margin:0 0 20px;padding-left:20px;">
        <li>营销更新：{{ !empty($marketing_updates) ? '开启' : '关闭' }}</li>
        <li>报告恢复：{{ !empty($report_recovery) ? '开启' : '关闭' }}</li>
        <li>产品更新：{{ !empty($product_updates) ? '开启' : '关闭' }}</li>
    </ul>

    <p style="margin:0 0 12px;"><a href="{{ $email_preferences_url }}">管理邮件偏好</a></p>
    <p style="margin:0 0 12px;"><a href="{{ $email_unsubscribe_url }}">退订邮件</a></p>
    <p style="margin:0 0 24px;"><a href="{{ $order_lookup_url }}">订单查询</a></p>

    <p style="margin:0 0 8px;">如果这次变更并非你本人操作，请检查设置并联系 <a href="mailto:{{ $support_email }}">{{ $support_email }}</a>。</p>

    <p style="margin:0;font-size:12px;color:#52606d;">
        <a href="{{ $privacy_url }}">隐私政策</a> |
        <a href="{{ $terms_url }}">服务条款</a> |
        <a href="{{ $refund_url }}">退款政策</a>
    </p>
</div>
</body>
</html>
