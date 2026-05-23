# SEO-GROWTH-MBTI-ACTION-01B-FIX-02 URL Truth Alignment

Task: `SEO-GROWTH-MBTI-ACTION-01B-FIX-02`

## Why this fix was needed

The MBTI/Search Channel scan found two backend-owned URL Truth defects:

- `BackendAuthorityUrlTruthSource::scaleCatalogCandidates()` emitted only EN `test_detail` candidates and never emitted the ZH MBTI public URL.
- absolute public canonical URLs were derived from `app.frontend_url`, which allowed production URL Truth to persist `https://www.fermatmind.com/...` while public runtime canonical had already converged to apex `https://fermatmind.com/...`.

This PR fixes URL Truth source generation only. It does not write production rows, enqueue Search Channel items, submit URLs, or change sitemap/`llms.txt`.

## What changed

### Canonical host alignment

URL Truth public canonical URLs now resolve from:

- `seo_intel.public_canonical_host`

Default:

- `https://fermatmind.com`

This keeps backend URL Truth deterministic and apex-aligned without relying on request host, `fap-web`, sitemap, or `llms.txt`.

### ZH MBTI test emission

Scale catalog candidates now emit localized public `test_detail` URLs for supported public locales:

- `https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types`
- `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`

Locale handling remains backend-safe:

- backend locale `zh-CN` maps to public path segment `/zh/`
- unsupported locale segments are not emitted
- private flows such as `/take`, `/result`, `/order`, and paywalled/report-private routes are not emitted

### Research apex alignment

Research URL Truth candidates now emit apex canonical URLs:

- `https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

The source authority and entity classification remain unchanged:

- `page_entity_type = research_report`
- `source_authority = backend_cms`
- `entity_source = research_reports`

Unsafe routes remain excluded:

- stale `turnover-rate-report`
- `/articles/*`
- `/reports/*`
- `www` host variants

## Authority boundary

Allowed authority:

- backend CMS / backend public surface / scale catalog

Forbidden authority:

- frontend fallback
- static sitemap
- static `llms.txt`
- crawler logs
- search engine responses
- Digital PR mentions
- local copies

This PR does not:

- write production URL Truth rows
- enqueue Search Channel
- perform live submission
- modify `fap-web`

## Expected candidates after deploy and bounded production preflight

- `https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types`
- `https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types`
- `https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

## Deferred follow-ups

- backend deploy/readiness
- bounded production URL Truth dry-run
- bounded production URL Truth write/preflight only with later approval
- sitemap exposure policy review
- later ZH/Search Channel enqueue after persisted rows are verified

## Next task

`BACKEND-DEPLOY-READINESS`
