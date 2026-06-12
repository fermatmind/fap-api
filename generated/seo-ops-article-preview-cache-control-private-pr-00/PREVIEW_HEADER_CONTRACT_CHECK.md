# Preview Header Contract Check

| Contract | Result | Evidence |
|---|---:|---|
| Authenticated article draft preview returns 200 | PASS | `ArticleDraftPreviewRouteTest` authenticated case passes |
| `X-Robots-Tag` includes `noindex` | PASS | Exact assertion remains `noindex, noarchive, nosnippet` |
| `X-Robots-Tag` includes `noarchive` | PASS | Exact assertion remains `noindex, noarchive, nosnippet` |
| `X-Robots-Tag` includes `nosnippet` | PASS | Exact assertion remains `noindex, noarchive, nosnippet` |
| `Cache-Control` includes `no-store` | PASS | Existing assertion retained |
| `Cache-Control` includes `private` | PASS | New assertion added |
| No canonical link | PASS | Existing `assertDontSee('rel="canonical"')` retained |
| No hreflang/alternate | PASS | Existing `assertDontSee('rel="alternate"')` retained |
| No JSON-LD/schema | PASS | Existing `assertDontSee('application/ld+json')` retained |
| Unauthenticated preview remains blocked | PASS | New 401 `admin_token_missing` test added |
| Public canonical route unaffected | PASS | No public route/controller/model/publish code changed |
