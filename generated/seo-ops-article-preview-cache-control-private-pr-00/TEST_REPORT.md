# Test Report

Commands run:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test tests/Feature/Ops/ArticleDraftPreviewRouteTest.php
```

Result:
- PASS: 4 tests, 26 assertions.

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
./vendor/bin/pint app/Http/Controllers/Ops/ArticleDraftPreviewController.php tests/Feature/Ops/ArticleDraftPreviewRouteTest.php
```

Result:
- PASS: Pint completed; one single-quote style fix applied in the test file.

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test tests/Feature/Ops/ArticleDraftPreviewRouteTest.php
```

Result after Pint:
- PASS: 4 tests, 26 assertions.

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
FAP_ADMIN_PANEL_ENABLED=true php artisan route:list --path=ops/article-preview
```

Result:
- PASS: `GET|HEAD ops/article-preview/{article}` registered as `ops.articles.preview` when Ops panel is enabled.

```bash
cd /Users/rainie/Desktop/GitHub/fap-api
git diff --check
```

Result:
- PASS: no whitespace errors.

## CI supply-chain rerun fix

GitHub `supply-chain` failed on `composer audit --locked` because new advisories affected the locked versions of `filament/tables` and `guzzlehttp/psr7`.

Remediation command:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
composer update filament/filament filament/actions filament/forms filament/infolists filament/notifications filament/support filament/tables filament/widgets guzzlehttp/psr7 --with filament/filament:3.3.52 --with guzzlehttp/psr7:2.10.2
```

Packages updated in `composer.lock`:
- `filament/*` panel components: `v3.3.47` -> `v3.3.52`
- `guzzlehttp/psr7`: `2.9.0` -> `2.10.2`

Post-fix validation:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable
php artisan test tests/Feature/Ops/ArticleDraftPreviewRouteTest.php
FAP_ADMIN_PANEL_ENABLED=true php artisan route:list --path=ops/article-preview
```

Result:
- PASS: `composer.json` valid.
- PASS: no security vulnerability advisories found.
- PASS: article draft preview route tests remain green.
- PASS: Ops article preview route remains registered when Ops panel is enabled.
