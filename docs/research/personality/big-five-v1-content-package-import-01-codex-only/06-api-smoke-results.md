# API Smoke Results

## Route Smoke

Command:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-bigfive-import-01.sqlite php artisan route:list --path=api/v0.5/personality-content-assets
```

Result:

- `GET|HEAD api/v0.5/personality-content-assets`
- `GET|HEAD api/v0.5/personality-content-assets/{framework}/{entityType}/{code}`
- `GET|HEAD api/v0.5/personality-content-assets/{framework}/{slug}`

## API Contract Test

Covered by:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --filter=PersonalityPublicContentAssetContractTest
```

Relevant behavior verified:

- Import dry-run validates Big Five seed.
- Write import is idempotent.
- Public API exposes only render candidates.
- Seed parity and indexability are enforced.
- Disallowed page families and private result modules are rejected.

## Testing Database Distribution

After write import:

- total: 94.
- locale: `{"en":47,"zh-CN":47}`.
- launch: `{"content_ready":34,"content_stub":60}`.
- entity: `{"domain":10,"facet":60,"facet_hub":2,"hub":2,"polarity":20}`.
- indexable/sitemap/llms: 0/0/0.
- public_readable: 34.
