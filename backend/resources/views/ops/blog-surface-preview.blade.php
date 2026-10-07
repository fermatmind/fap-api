<!doctype html>
<html lang="{{ $record->locale === 'en' ? 'en' : 'zh-CN' }}">
<head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
    <meta name="robots" content="noindex,noarchive,nosnippet"><meta name="referrer" content="no-referrer">
    <title>CMS Blog Layout Preview</title>
</head>
<body>
    <main>
        <h1>CMS Blog Layout Preview</h1>
        <p>Surface {{ $record->id }} · {{ $record->locale }} · {{ $record->status }}</p>
        <p>Configuration SHA256: <code>{{ $payload['configuration_sha256'] }}</code></p>
        <p>Package SHA256: <code>{{ $payload['binding']['package_sha256'] }}</code></p>
        <p>Candidate SHA256: <code>{{ $payload['binding']['candidate_sha256'] }}</code></p>
        <p>Surface state SHA256: <code>{{ $payload['binding']['surface_state_sha256'] }}</code></p>
        <p>Configured owner: {{ $payload['binding']['owner_admin_user_id'] }}</p>
        <p>Read-only draft layout. Article cards use current public revisions. Opening this preview does not review, approve or publish content.</p>
        @if ($payload['blog']['configuration_state'] === 'published')
            <button type="button" id="open-blog-layout" data-preview-url="{{ $previewUrl }}">Open blog layout preview</button>
            <p id="blog-preview-status" role="status">Keep this authenticated tab open while reviewing the layout.</p>
        @else
            <p role="alert">The stored blog configuration is unavailable or invalid. It cannot be previewed.</p>
        @endif
        <script id="blog-preview-data" type="application/json">{!! json_encode($payload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_THROW_ON_ERROR) !!}</script>
    </main>
    <script src="/ops/blog-preview/script.js" defer></script>
</body>
</html>
