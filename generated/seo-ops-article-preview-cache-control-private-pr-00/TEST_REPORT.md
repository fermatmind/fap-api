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
