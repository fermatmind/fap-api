<!doctype html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <title>電子郵件偏好已更新</title>
</head>
<body style="margin:0;padding:24px;background:#f6f4ef;font-family:Arial,sans-serif;color:#1f2933;line-height:1.7;">
<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;padding:32px;">
    <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;">電子郵件偏好已更新</h1>
    <p style="margin:0 0 16px;">這封系統通知用來確認你的 FermatMind 電子郵件設定已更新。</p>

    <p style="margin:0 0 8px;"><strong>目前設定</strong></p>
    <ul style="margin:0 0 20px;padding-left:20px;">
        <li>行銷更新：{{ !empty($marketing_updates) ? '開啟' : '關閉' }}</li>
        <li>報告找回：{{ !empty($report_recovery) ? '開啟' : '關閉' }}</li>
        <li>產品更新：{{ !empty($product_updates) ? '開啟' : '關閉' }}</li>
    </ul>

    <p style="margin:0 0 12px;"><a href="{{ $email_preferences_url }}">管理電子郵件偏好</a></p>
    <p style="margin:0 0 12px;"><a href="{{ $email_unsubscribe_url }}">取消訂閱電子郵件</a></p>
    <p style="margin:0 0 24px;"><a href="{{ $order_lookup_url }}">訂單查詢（簡體中文）</a></p>

    <p style="margin:0 0 8px;">如果這次變更並非你本人操作，請檢查設定並聯絡 <a href="mailto:{{ $support_email }}">{{ $support_email }}</a>。</p>

    <p style="margin:0;font-size:12px;color:#52606d;">
        <a href="{{ $privacy_url }}">隱私政策</a> |
        <a href="{{ $terms_url }}">服務條款</a> |
        <a href="{{ $refund_url }}">退款政策（簡體中文）</a>
    </p>
</div>
</body>
</html>
