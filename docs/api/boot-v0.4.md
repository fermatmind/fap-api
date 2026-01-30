# Boot API v0.4

## Endpoint
- `GET /api/v0.4/boot`

## Request headers
- `X-Region`: region code (CN_MAINLAND, US, EU). Missing/invalid -> CN_MAINLAND.
- `Accept-Language`: first locale tag is used (e.g. `en-US,en;q=0.9`). Missing -> region default locale.
- `If-None-Match`: optional ETag for 304.

## Response (200)
```json
{
  "ok": true,
  "region": "CN_MAINLAND",
  "locale": "zh-CN",
  "currency": "CNY",
  "cdn": {
    "assets_base_url": "http://localhost/storage/content_assets"
  },
  "payment_methods": ["wechatpay", "alipay", "stub"],
  "compliance": {
    "pipl": true,
    "gdpr": false,
    "legal_urls": {
      "terms": "http://localhost/legal/terms",
      "privacy": "http://localhost/legal/privacy",
      "refund": "http://localhost/legal/refund"
    }
  },
  "experiments": {
    "boot_experiments": []
  },
  "feature_flags_version": "v0.4",
  "policy_versions": {
    "terms": "2026-01-01",
    "privacy": "2026-01-01",
    "refund": "2026-01-01"
  }
}
```

## Cache headers
- `Cache-Control: public, max-age=300`
- `Vary: X-Region, Accept-Language`
- `ETag: "<sha1>"`
- If `If-None-Match` matches, returns `304` with the same cache headers and empty body.

## Region/locale resolution
- Region comes from `X-Region`. If missing/invalid, fall back to `config('regions.default_region')` and then `CN_MAINLAND`.
- Locale comes from the first `Accept-Language` tag. If missing, fall back to `regions.<region>.default_locale` and then `config('content_packs.default_locale')`.
- Currency comes from `regions.<region>.currency` with a CNY fallback.

## CDN base URL
- Boot response uses `config('cdn_map')` for `cdn.assets_base_url`.
- Asset URL resolution (PR16) follows priority:
  1) `cdn_map` region override
  2) `version.json.assets_base_url`
  3) `APP_URL/storage/content_assets`
