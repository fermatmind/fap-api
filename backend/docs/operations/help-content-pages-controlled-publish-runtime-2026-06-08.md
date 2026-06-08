# HELP-CONTENT-PAGES-CONTROLLED-PUBLISH-RUNTIME-01

## Decision

Runtime ready for a future Help service controlled publish dry-run/execution path. Publish is still not allowed by this PR.

## Scope

- Adds `content-pages:publish-controlled --scope=help-service`.
- Targets exactly 12 existing Help service `content_pages` rows: 6 slugs across `zh-CN` and `en`.
- Keeps the existing `global-en-wave1` runtime behavior as the default.
- Does not publish, mutate production CMS data, deploy, submit search URLs, access private URLs, read secrets, perform payment/refund actions, change payment provider behavior, or claim Operator approval.

## Runtime Command Shape

```bash
php artisan content-pages:publish-controlled --scope=help-service --locale=all --keys=help-unlock-failure,help-payment-refund,help-result-recovery,help-privacy-data,help-use-boundaries,help-data-deletion --dry-run --json
```

Future `--execute` remains blocked until an exact publish authorization is provided after `HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-R2-01`.

## Guards

- Dry-run is default unless `--execute` is passed.
- `--scope=help-service` requires `--locale=all`.
- The exact six Help service slugs are required.
- Runtime queries only existing `content_pages` rows.
- Missing rows fail closed; no create/upsert path exists.
- Out-of-scope keys such as `help-faq`, `privacy`, `terms`, and `about` are rejected.
- Target rows must remain non-indexable; sitemap, llms, footer, nav, search submission, and deploy are out of scope.
- Help rows must carry `support@fermatmind.com`, `help_service_policy.v1`, a reviewer value, and four structured FAQ items.

## Validation Intent

Focused tests cover:

- Help service dry-run over 12 rows without writes.
- Help service execute in the local sqlite fixture publishing only the 12 seeded rows.
- No record creation.
- Rejection of single-locale Help publish attempts.
- Rejection of extra keys.
- Existing five-page English content runtime behavior remains covered.

## Next

Run `HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-R2-01` as a read-only publish preflight. Do not publish until that preflight passes and the user provides exact publish authorization.
