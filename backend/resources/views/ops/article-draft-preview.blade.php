<!doctype html>
<html lang="{{ str_starts_with(strtolower((string) $article->locale), 'zh') ? 'zh-CN' : 'en' }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex,noarchive,nosnippet">
    <meta name="googlebot" content="noindex,noarchive,nosnippet">
    <title>{{ $seoTitle }} · Draft preview</title>
    <style>
        :root {
            color-scheme: light;
            --bg: #f6f3ed;
            --card: #fffaf1;
            --ink: #231f1a;
            --muted: #746b60;
            --line: #ded5c8;
            --accent: #0f766e;
            --danger: #b42318;
        }
        * { box-sizing: border-box; }
        body {
            margin: 0;
            background: radial-gradient(circle at top left, #e5f3ef, transparent 32rem), var(--bg);
            color: var(--ink);
            font-family: ui-serif, Georgia, Cambria, "Times New Roman", Times, serif;
            line-height: 1.65;
        }
        a { color: var(--accent); }
        .shell { margin: 0 auto; max-width: 1120px; padding: 32px 20px 56px; }
        .notice, .card {
            border: 1px solid var(--line);
            border-radius: 22px;
            background: rgba(255, 250, 241, 0.92);
            box-shadow: 0 18px 48px rgba(35, 31, 26, 0.08);
        }
        .notice { padding: 18px 20px; }
        .notice strong { color: var(--danger); }
        .grid { display: grid; grid-template-columns: minmax(0, 1fr) 320px; gap: 22px; margin-top: 22px; }
        .card { padding: 28px; }
        .rail { align-self: start; position: sticky; top: 20px; }
        .eyebrow {
            display: inline-flex;
            border: 1px solid #99d5ce;
            border-radius: 999px;
            padding: 4px 10px;
            color: #115e59;
            font: 700 12px/1.2 ui-sans-serif, system-ui, sans-serif;
            letter-spacing: .08em;
            text-transform: uppercase;
        }
        h1 { margin: 18px 0 12px; font-size: clamp(36px, 7vw, 68px); line-height: .95; letter-spacing: -.045em; }
        h2 { margin-top: 32px; font-size: 28px; line-height: 1.15; }
        h3 { margin-top: 24px; font-size: 22px; }
        .meta, .summary { color: var(--muted); }
        .body { font-size: 18px; }
        .body img { max-width: 100%; border-radius: 18px; }
        .media-preview {
            margin: 22px 0;
        }
        .media-preview img {
            display: block;
            max-width: 100%;
            border-radius: 18px;
            border: 1px solid var(--line);
        }
        .media-preview figcaption {
            margin-top: 8px;
            color: var(--muted);
            font: 13px/1.45 ui-sans-serif, system-ui, sans-serif;
        }
        .body code, .field code {
            border-radius: 8px;
            background: #efe7da;
            padding: 2px 6px;
        }
        .fields { display: grid; gap: 12px; margin-top: 18px; }
        .field {
            border-top: 1px solid var(--line);
            padding-top: 12px;
            font: 14px/1.45 ui-sans-serif, system-ui, sans-serif;
        }
        .field span { display: block; color: var(--muted); font-size: 12px; text-transform: uppercase; letter-spacing: .08em; }
        .flag-list { margin: 0; padding-left: 18px; color: var(--muted); font: 14px/1.6 ui-sans-serif, system-ui, sans-serif; }
        @media (max-width: 840px) {
            .grid { grid-template-columns: 1fr; }
            .rail { position: static; }
        }
    </style>
</head>
<body>
    <main class="shell">
        <section class="notice" aria-label="Preview safety boundary">
            <strong>Draft preview only.</strong>
            This page is authenticated, noindex, noarchive, nosnippet, no-store, and is not a canonical public article URL.
            It does not publish, index, submit, revalidate, emit schema, or emit hreflang.
        </section>

        <div class="grid">
            <article class="card">
                <span class="eyebrow">CMS Article Draft Preview</span>
                <h1>{{ $title }}</h1>
                @if ($excerpt !== '')
                    <p class="summary">{{ $excerpt }}</p>
                @endif

                @if ($coverImageUrl)
                    <p><img src="{{ $coverImageUrl }}" alt="{{ $article->cover_image_alt ?: $title }}"></p>
                @endif

                @if ($bodyVisual)
                    <figure class="media-preview" data-preview-media="body_visual">
                        <img src="{{ $bodyVisual['image_url'] }}" alt="Body visual preview for {{ $title }}">
                        <figcaption>Body visual from public API media metadata.</figcaption>
                    </figure>
                @endif

                <section class="body">
                    {!! $bodyHtml !!}
                </section>
            </article>

            <aside class="card rail">
                <span class="eyebrow">Safety state</span>
                <div class="fields">
                    <div class="field"><span>Article ID</span>{{ $previewContext['article_id'] }}</div>
                    <div class="field"><span>Working revision</span>{{ $previewContext['working_revision_id'] ?? 'none' }}</div>
                    <div class="field"><span>Status</span>{{ $previewContext['status'] }}</div>
                    <div class="field"><span>Public URL candidate</span>{{ $publicUrl ?? 'not available' }}</div>
                    <div class="field"><span>Canonical metadata value</span>{{ $canonicalUrl ?? 'not set' }}</div>
                    <div class="field"><span>SEO title</span>{{ $seoTitle }}</div>
                    <div class="field"><span>SEO description</span>{{ $seoDescription !== '' ? $seoDescription : 'not set' }}</div>
                    <div class="field"><span>Private URL redactions</span>{{ $redactionCount }}</div>
                    <div class="field"><span>Body visual asset key</span>{{ $bodyVisual['asset_key'] ?? 'not set' }}</div>
                    <div class="field"><span>Body visual URL</span>{{ $bodyVisual['image_url'] ?? 'not set' }}</div>
                    <div class="field"><span>Body visual fallback authorized</span>{{ ($bodyVisual['fallback_authorized'] ?? false) ? 'true' : 'false' }}</div>
                </div>

                <h2>Hard holds</h2>
                <ul class="flag-list">
                    <li>is_public: {{ $previewContext['is_public'] ? 'true' : 'false' }}</li>
                    <li>is_indexable: false for preview</li>
                    <li>sitemap_eligible: false</li>
                    <li>llms_eligible: false</li>
                    <li>search_submission_allowed: false</li>
                    <li>schema_enabled: false</li>
                    <li>hreflang_enabled: false</li>
                    <li>revalidation_allowed: false</li>
                </ul>
            </aside>
        </div>
    </main>
</body>
</html>
