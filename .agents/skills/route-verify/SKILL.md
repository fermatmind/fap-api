---
name: route-verify
description: Verify Laravel API routes are correctly defined, grouped, and protected. Covers route listing, auth guard checks, and career route enumeration.
---

# Route Verification

## Purpose
Verify that Laravel API routes are correctly registered, grouped under the right version prefixes, protected by the correct auth guards, and that no route registration errors exist.

## When to Use
- After adding or modifying routes in `backend/routes/api.php`
- Before opening a PR that touches routes
- When debugging route registration or auth guard issues
- When verifying career canonical routes are correctly grouped

## When Not to Use
- For content pack changes — use `validate-content-pack`
- For controller/service logic without route changes — use `ci-verify`
- For frontend routing (fap-web) — use fap-web skills

## Hard Invariants
- **Do not** register routes without a version prefix (`v0.3`, `v0.4`).
- **Do not** leave routes unprotected without an explicit auth guard.
- **Do not** skip `route:list` verification after route changes.
- **Do not** modify `api.php` without verifying the route appears in the listing.

## Standard Workflow

### Step 1 — Full Route Listing
```bash
cd backend
php artisan route:list --path=api --except-vendor
```
Check for:
- All routes properly prefixed
- Auth guards assigned
- No duplicate route names

### Step 2 — Career Routes
```bash
php artisan route:list --path=career
```
Verify career canonical, publish, and recommendation routes.

### Step 3 — Route Count Verification
```bash
# Count routes by version
php artisan route:list --path=api/v0.3 --except-vendor --json | python3 -c "import sys,json; print(len(json.load(sys.stdin)))"
php artisan route:list --path=api/v0.4 --except-vendor --json | python3 -c "import sys,json; print(len(json.load(sys.stdin)))"
```

### Step 4 — JSON Export
```bash
php artisan route:list --path=api --except-vendor --json > /tmp/routes.json
# Verify structure, check for missing middleware
```

## Acceptance Commands
```bash
cd backend
php artisan route:list --path=api --except-vendor
php artisan route:list --path=career
php artisan route:list --path=api --except-vendor --json | python3 -c "import sys,json; routes=json.load(sys.stdin); print(f'{len(routes)} routes'); print('\n'.join(r['uri'] for r in routes))"
```

## Output Contract
- `route:list` must enumerate all registered API routes
- Each route must show: method, URI, name, action, middleware
- Career routes must be grouped under `career` prefix
- JSON export must be valid JSON with all route fields

## Stop Conditions
- Route missing from `route:list` output after adding to `api.php`
- Duplicate route name detected
- Route registered without auth guard
- Route registered without version prefix
- Career routes not appearing under `--path=career`
