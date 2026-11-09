<!doctype html>
<html lang="zh-TW">
<head>
    <meta charset="utf-8">
    <title>報告找回郵件</title>
</head>
<body style="margin:0;padding:24px;background:#f6f4ef;font-family:Arial,sans-serif;color:#1f2933;line-height:1.7;">
<div style="max-width:640px;margin:0 auto;background:#ffffff;border:1px solid #e5e7eb;padding:32px;">
    <h1 style="margin:0 0 16px;font-size:28px;line-height:1.2;">找回報告</h1>
    <p style="margin:0 0 16px;">你已提出找回報告的請求，可以使用下方連結再次查看報告。</p>
    @if(!empty($order_no))
        <p style="margin:0 0 8px;"><strong>訂單編號：</strong>{{ $order_no }}</p>
    @endif

    @if(!empty($report_url))
        <p style="margin:0 0 12px;"><a href="{{ $report_url }}">查看報告（原語）</a></p>
    @endif
    @if(!empty($report_pdf_url))
        <p style="margin:0 0 12px;"><a href="{{ $report_pdf_url }}">下載 PDF（原語）</a></p>
    @endif
    <p style="margin:0 0 24px;"><a href="{{ $order_lookup_url }}">訂單查詢（原語）</a></p>

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
