<!doctype html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <title>測驗結果連結</title>
</head>
<body style="margin:0;padding:24px;background:#f6f4ef;font-family:Arial,sans-serif;color:#1f2933;line-height:1.7;">
<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;padding:32px;">
    <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;">你的測驗結果連結</h1>
    <p style="margin:0 0 16px;">點選下方連結即可回到你的 FermatMind 結果頁。連結有使用期限，請勿轉寄給他人。</p>

    @if(!empty($result_access_url))
        <p style="margin:0 0 12px;"><a href="{{ $result_access_url }}">查看結果（簡體中文）</a></p>
    @endif
    @if(!empty($result_access_token_expires_at))
        <p style="margin:0 0 24px;font-size:13px;color:#52606d;">有效期限：{{ $result_access_token_expires_at }}</p>
    @endif

    <p style="margin:0 0 8px;">如需協助，請聯絡 <a href="mailto:{{ $support_email }}">{{ $support_email }}</a>。</p>
    <p style="margin:0 0 8px;"><a href="{{ $email_preferences_url }}">管理電子郵件偏好</a></p>
    <p style="margin:0 0 24px;"><a href="{{ $email_unsubscribe_url }}">取消訂閱電子郵件</a></p>

    <p style="margin:0;font-size:12px;color:#52606d;">
        <a href="{{ $privacy_url }}">隱私權政策</a> |
        <a href="{{ $terms_url }}">服務條款</a> |
        <a href="{{ $refund_url }}">退款政策</a>
    </p>
</div>
</body>
</html>
