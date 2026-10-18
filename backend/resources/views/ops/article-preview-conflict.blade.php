<!doctype html>
<html lang="{{ app()->getLocale() === 'zh_CN' ? 'zh-CN' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,noarchive,nosnippet">
    <title>{{ __('ops.custom_pages.seo_operations.article_workspace.preview_expired') }}</title>
    <style>
        body { margin: 0; background: #f6f3ed; color: #231f1a; font: 17px/1.65 system-ui, sans-serif; }
        main { max-width: 640px; margin: 12vh auto; padding: 32px; background: #fffaf1; border: 1px solid #ded5c8; border-radius: 20px; }
        h1 { font-size: 28px; line-height: 1.3; }
        a { display: inline-block; margin-top: 12px; color: #0f766e; }
    </style>
</head>
<body>
    <main>
        <p>Article #{{ $articleId }} · 409</p>
        <h1>{{ __('ops.custom_pages.seo_operations.article_workspace.preview_expired') }}</h1>
        <p>{{ __('ops.custom_pages.seo_operations.article_workspace.preview_expired_hint') }}</p>
        <a href="{{ $workspaceUrl }}">{{ __('ops.custom_pages.seo_operations.article_workspace.return_to_article') }}</a>
    </main>
</body>
</html>
