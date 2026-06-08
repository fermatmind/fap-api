# HELP-CONTENT-DRAFT-POLICY-CMS-SYNC-01

Date: 2026-06-08

Decision: `CMS_DRAFT_POLICY_SYNC_COMPLETE_PUBLISH_BLOCKED`

## Scope

This PR synced the revised v01 Help service source package into the existing 12 Help `ContentPage` CMS drafts.

Allowed CMS mutation was limited to the existing 12 Help draft rows and the revised source content plus first-class Help service fields:

- `support_contact`
- `policy_version`
- `reviewer`
- `faq_items`
- `schema_enabled`

No publish, deploy, search submission, private URL access, secret/env/cookie/token read, payment/refund action, payment-provider behavior change, or Operator approval claim was performed.

## Source Evidence

- Source file: `backend/docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json`
- Source SHA-256: `7d034de0b0eb5dc2c78fbb0e1828f3820e36f1c1a03d8f1567722c336438b453`
- Source rows: 12
- Local contract check: `HelpContentImportPackageTest` passed with 524 assertions.

Keyword checks found Help boundary language that tells users not to post full order numbers, payment identifiers, result links, history links, or private URLs publicly. No raw private ID or private URL value was recorded in this report.

## Production Execution

Runtime revision: `a98788512c1513750931504d4162d528ad19cc54`

Temporary source dir: `/tmp/fm-help-policy-cms-sync.IjY3nH`

Dry-run command:

```text
php artisan content-pages:import-local-baseline --dry-run --upsert --status=draft --source-dir=/tmp/fm-help-policy-cms-sync.IjY3nH --no-ansi
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
php artisan content-pages:import-local-baseline --upsert --status=draft --source-dir=/tmp/fm-help-policy-cms-sync.IjY3nH --no-ansi
```

Import result:

```text
files_found=1
pages_found=12
will_create=0
will_update=12
will_skip=0
```

Temporary source dir was removed after import.

## Post-Sync State

Post-sync read-only CMS check returned:

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

Public canonical route absence check returned 404 for all 12 zh/en Help routes.

## Remaining Gates

- Operator review is still not passed.
- Publish remains blocked.
- Search submission remains blocked.
- Next recommended task: `HELP-CONTENT-DRAFT-OPERATOR-REVIEW-R2-01`.
