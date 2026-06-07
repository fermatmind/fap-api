# HELP-CMS-SERVICE-FIELDS-PROD-RUNTIME-01

Decision: `PRODUCTION_RUNTIME_SHA_UNKNOWN_DEPLOY_PROMPT_REQUIRED`

This read-only PR checks whether production runtime can be proven to contain the merged ContentPage Help service fields migration, publish safety fields migration, and `content-pages:import-local-baseline` service-field mapping. It does not deploy, mutate CMS rows, publish, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund actions, change payment-provider behavior, or claim Operator approval.

## Target

- Target SHA: `b70cd7291810b7f13416c5e51cbcbd8daabaf0cd`
- Basis: current `origin/main` at scan time.
- Included dependency: PR #1956, merge `9cae1f45ee55acf11df43376e80387957c2049a3`
- Included dependency: PR #1959, merge `13e62a0389124e4acb90f4b7467a26ea996cf24e`
- Newer included main commit: PR #1960, merge `d8550f1c689d051445b6ae1b67624222dda5ca1a`
- Newer included main commit: PR #1961, merge `b70cd7291810b7f13416c5e51cbcbd8daabaf0cd`

## Local Main Evidence

- `origin/main` contains PR #1956 importer mapping.
- `origin/main` contains PR #1959 publish safety fields.
- `origin/main` contains PR #1960, which is newer than the Help runtime dependency and would be included in a latest-main deploy.
- `origin/main` contains PR #1961, which is newer than the Help runtime dependency and would be included in a latest-main deploy.
- Help service migration exists at `backend/database/migrations/2026_06_05_150000_add_help_service_fields_to_content_pages.php`.
- Publish safety migration exists at `backend/database/migrations/2026_06_08_010000_add_publish_safety_fields_to_content_pages.php`.
- Importer maps `support_contact`, `policy_version`, `reviewer`, `faq_items`, and `schema_enabled`.

## Public Production Evidence

- API root status: `200`
- Public `/api/healthz` status: `404`
- Public draft Help content page route status: `404`
- Public release SHA visible: `false`
- Production active SHA: `Unknown`
- Production contains target SHA: `Unknown`

The public 404 on draft Help pages is compatible with draft/non-public state. It does not prove production runtime has or lacks the target SHA.

## Decision

`HELP-CONTENT-DRAFT-POLICY-CMS-SYNC-01` is not ready from this evidence alone. The CMS sync should wait until production is confirmed to contain `b70cd7291810b7f13416c5e51cbcbd8daabaf0cd` or a newer SHA containing it.

## Exact Deploy Authorization Prompt

```text
I explicitly approve backend production deploy for exact SHA b70cd7291810b7f13416c5e51cbcbd8daabaf0cd release help-cms-service-fields-prod-runtime-20260608-b70cd729.
```

## Deferred

- No deploy was performed.
- No CMS mutation was performed.
- No publish was performed.
- No search submission was performed.
- No private URL was accessed.
- No secret/env/cookie/token was read.
- No payment/refund action was performed.
- No Operator approval was claimed.

## Validation

```bash
git rev-parse origin/main
git merge-base --is-ancestor 9cae1f45ee55acf11df43376e80387957c2049a3 origin/main
git merge-base --is-ancestor b70cd7291810b7f13416c5e51cbcbd8daabaf0cd origin/main
python3 -m json.tool backend/docs/help/generated/help-cms-service-fields-prod-runtime-01.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/docs/help/generated/help-cms-service-fields-prod-runtime-01.v1.json backend/docs/operations/help-cms-service-fields-prod-runtime-2026-06-08.md docs/codex/pr-train.yaml docs/codex/pr-train-state.json
git diff --cached --check
```
