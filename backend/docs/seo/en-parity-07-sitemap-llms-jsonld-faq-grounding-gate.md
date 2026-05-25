# EN-PARITY-07 Sitemap / llms / JSON-LD / FAQ Grounding Gate

## Executive Summary

EN-PARITY-07 lands the backend authority gate for public SEO and answer surfaces. It records the rules that sitemap, `llms.txt`, `llms-full.txt`, canonical/hreflang, JSON-LD, and FAQPage output must satisfy before English pages can be considered aligned with Chinese authority.

Decision: `en_parity_07_authority_gate_landed_frontend_runtime_contract_deferred`

This PR does not mutate production, does not submit URLs, does not change fap-web runtime, and does not publish content.

## Gate Inputs

The gate is based on the landed EN-PARITY artifacts:

- EN-PARITY-00 full-site bilingual inventory.
- EN-PARITY-01 URL Truth / hard 404 / soft 404 / canonical baseline.
- EN-PARITY-02 translation group read model.
- EN-PARITY-03 content page import package.
- EN-PARITY-04 article counterpart import package.
- EN-PARITY-05 career guide detail import package.
- EN-PARITY-06 media assets parity inventory.

## Required Rules

Sitemap and llms surfaces must exclude:

- 404
- soft 404
- noindex
- private flows
- draft pages
- placeholder pages
- frontend fallback-only pages
- missing-authority pages
- stale slugs
- staging host URLs
- `www` host canonical contamination
- unsupported claim-boundary pages

Canonical/hreflang rules:

- Hreflang targets must be valid canonical reciprocal counterparts.
- Counterpart lookup must use translation group or backend authority key.
- Slug guessing alone is not allowed.
- Staging and `www` canonical hosts are blocked.

JSON-LD and FAQ grounding rules:

- Article schema requires published Article authority.
- Dataset schema requires ResearchReport and dataset asset authority.
- FAQPage requires visible FAQ content or backend authority-backed FAQ blocks.
- JSON-LD image fields require authority-backed media metadata.
- Frontend fallback FAQ is not authority.

## Current Limit

This backend PR records and tests the authority gate. It does not change fap-web, where final production HTML metadata, JSON-LD, and public text surfaces are emitted.

Follow-up fap-web contracts should assert:

- sitemap/llms exclude draft, placeholder, missing-authority, private, noindex, 404, and soft-404 URLs;
- JSON-LD Article/Dataset/FAQPage is emitted only from backend/CMS authority;
- hreflang targets only valid canonical reciprocal counterparts.

## Deferred Items

1. `en_parity_07_frontend_runtime_contract`
   - fap-web must enforce the runtime HTML/metadata contract after backend authority is available.

2. `en_parity_07_live_surface_recheck`
   - public runtime recheck belongs after deployment; this PR does not deploy or mutate production.

3. `en_parity_07_media_visual_review_dependency`
   - EN-PARITY-06 media sidecars remain open for shared article covers and default share images.

## Validation

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter=EnParity07 --no-ansi
php artisan route:list --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable

cd /Users/rainie/Desktop/GitHub/fap-api
python3 -m json.tool backend/docs/seo/generated/en-parity-07-sitemap-llms-jsonld-faq-grounding-gate.v1.json >/dev/null
python3 - <<'PY'
import yaml, json
yaml.safe_load(open('docs/codex/pr-train.yaml'))
json.load(open('docs/codex/pr-train-state.json'))
print('manifest/state parse ok')
PY
git diff --check
git diff --cached --check
```

## Next Task

EN-PARITY-08 should perform the fap-web Chrome / Playwright visual parity pass. If EN-PARITY-08 discovers metadata/JSON-LD runtime violations, those should be split into linked backend/frontend PRs rather than fixed as visual-only CSS work.
