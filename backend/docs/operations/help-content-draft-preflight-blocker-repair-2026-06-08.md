# HELP-CONTENT-DRAFT-PREFLIGHT-BLOCKER-REPAIR-01

Status: `local_package_repaired`

This PR repairs the local Help service content package and ContentPage import source that feed the 12 Help CMS draft rows. It does not mutate CMS rows, publish, deploy, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund flows, or change payment-provider behavior.

## Decision

`LOCAL_PACKAGE_PAYMENT_ID_PHRASE_GATE_REPAIRED_CMS_SYNC_STILL_REQUIRED`

The local package no longer matches the blocked `payment_id` phrase gate. Production CMS draft rows are not changed in this PR and still require a separately authorized CMS sync before publish preflight can pass this gate in production.

## Repair

| Row | Locale | Change |
| --- | --- | --- |
| `help-unlock-failure` | `en` | Replaced the blocked payment identifier phrase with a non-ID privacy-boundary phrase. |
| `help-payment-refund` | `en` | Replaced the blocked payment identifiers phrase with a non-ID privacy-boundary phrase. |
| `help-data-deletion` | `en` | Replaced the blocked payment identifiers phrase with a non-ID privacy-boundary phrase. |

## Local Evidence

| Check | Result |
| --- | --- |
| Import package rows | 12 |
| ContentPage source rows | 12 |
| `/orders/`, `/result/`, `/pay/` pattern hits | 0 |
| `payment[_ -]?id` pattern hits | 0 |
| `transaction[_ -]?id` pattern hits | 0 |
| `token=` pattern hits | 0 |
| Raw `orderNo` / `order_no` pattern hits | 0 |
| CMS mutation | no |
| Publish | no |

## Deferred

| Task | Why |
| --- | --- |
| `HELP-CONTENT-DRAFT-BLOCKER-CMS-SYNC-01` | Needs explicit CMS mutation authorization to sync the repaired local package into existing draft rows. |
| `HELP-SERVICE-FAQ-SCHEMA-RUNTIME-01` | Separate fap-web runtime PR; not part of this content-package repair. |
| `HELP-CONTENT-PAGES-CONTROLLED-PUBLISH-RUNTIME-01` | Separate backend controlled publish runtime PR; not part of this phrase repair. |
| `HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-R2-01` | Must rerun after CMS sync and runtime repairs. |

## Validation

```bash
python3 -m json.tool backend/docs/help/import-packages/help-service-content-drafts-01.import.v1.json >/dev/null
python3 -m json.tool backend/docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json >/dev/null
python3 -m json.tool backend/docs/help/generated/help-content-draft-preflight-blocker-repair-01.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
python3 -c "import json, pathlib, re; text='\n'.join(pathlib.Path(p).read_text() for p in ['backend/docs/help/import-packages/help-service-content-drafts-01.import.v1.json','backend/docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json']); assert not re.search(r'payment[_ -]?id|transaction[_ -]?id|/orders/|/result/|/pay/|[?&]token=', text, re.I)"
cd backend && APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test --filter=HelpContentImportPackageTest --no-ansi
git diff --check -- backend/docs/help/import-packages/help-service-content-drafts-01.import.v1.json backend/docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json backend/docs/help/generated/help-content-draft-preflight-blocker-repair-01.v1.json backend/docs/operations/help-content-draft-preflight-blocker-repair-2026-06-08.md backend/tests/Feature/Cms/HelpContentImportPackageTest.php docs/codex/pr-train.yaml docs/codex/pr-train-state.json
git diff --cached --check
```
