# HELP-CONTENT-DRAFT-BLOCKER-CMS-SYNC-01

Date: 2026-06-08

Decision: `CMS_DRAFT_BLOCKER_SYNC_COMPLETE_PUBLISH_STILL_BLOCKED`

## Scope

This PR synced the repaired local Help service source package into the existing 12 Help `ContentPage` CMS drafts.

Allowed CMS mutation was limited to the existing 12 Help draft rows. No publish, deploy, search submission, private result/order/share/pay/payment/history URL access, secret/env/cookie/token read, payment/refund action, payment-provider behavior change, or Operator approval claim was performed.

## Source Evidence

- ContentPage source: `backend/docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json`
- ContentPage source SHA-256: `af59792b896892fa308ccddab0915e72c565e3f696bc10feb0af2c96f6c54d6d`
- Import package: `backend/docs/help/import-packages/help-service-content-drafts-01.import.v1.json`
- Import package SHA-256: `15843defa1d3925624a49844e0ff15244a6cdff6cbb7c93bd3eac3c7fa5bed44`
- Source rows: 12

Local boundary scan returned zero hits for `payment_id`, `transaction_id`, `/orders/`, `/result/`, `/pay/`, and token parameters.

## Production Execution

- Runtime revision: `8570a335c5cc539507b3dad4d8659fc9c971d759`
- Temporary source dir: `/tmp/fm-help-blocker-cms-sync.3r25GW`
- Temporary source dir removed: yes

Dry-run command:

```text
php artisan content-pages:import-local-baseline --dry-run --upsert --status=draft --source-dir=/tmp/fm-help-blocker-cms-sync.3r25GW --no-ansi
```

Dry-run result:

```text
files_found=1
pages_found=12
will_create=0
will_update=12
will_skip=0
```

Import command:

```text
php artisan content-pages:import-local-baseline --upsert --status=draft --source-dir=/tmp/fm-help-blocker-cms-sync.3r25GW --no-ansi
```

Import result:

```text
files_found=1
pages_found=12
will_create=0
will_update=12
will_skip=0
```

## Post-Sync State

Post-sync read-only CMS summary returned:

- checked rows: 12
- draft: 12
- non-public: 12
- non-indexable: 12
- unpublished: 12
- owner review: 12
- `support_contact=support@fermatmind.com`: 12
- `policy_version=help_service_policy.v1`: 12
- `reviewer=Unknown`: 12
- `schema_enabled=false`: 12
- `faq_items` count 4: 12
- `payment_id` hits: 0
- `transaction_id` hits: 0
- `payment identifier` hits: 0
- private route hits: 0
- token parameter hits: 0

Public canonical route absence checks returned `308 -> 404` for all 12 zh/en Help routes, so the draft rows are still not publicly visible.

## Remaining Gates

- `HELP-SERVICE-FAQ-SCHEMA-RUNTIME-01`: frontend schema runtime still needs to consume CMS `faq_items` / `schema_enabled`.
- `HELP-CONTENT-PAGES-CONTROLLED-PUBLISH-RUNTIME-01`: backend still needs bounded controlled publish runtime for Help `ContentPage` rows.
- `HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-R2-01`: rerun publish preflight after both runtime blockers are fixed.
- Publish remains blocked until exact publish authorization is provided.
