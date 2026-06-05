# HELP-CONTENT-DRAFT-CREATE-01

## Decision

`CMS_DRAFT_CREATE_COMPLETED_WITH_SIDECARS`

Six bilingual Help service `ContentPage` draft pairs were created in the target CMS by using the existing controlled importer in draft mode. No publish, search submission, deploy, private URL access, payment/refund action, payment-provider change, secret/env/cookie/token read, or public amplification was performed.

The server password pasted in the chat was not used. Execution used the approved SSH alias `fap-api-prod` with non-interactive key/alias access.

## Source

- Source package: `HELP-SERVICE-CONTENT-DRAFTS-01`
- Source adapter copied to a temporary server source directory from:
  - `backend/docs/help/import-packages/content-pages-draft-source/content_pages.help_service_drafts_01.json`
- Temporary source directory:
  - `/tmp/fm-help-drafts.nnYsTB`
- Temporary source cleanup:
  - removed after import

## Dry Run

Command:

```text
php artisan content-pages:import-local-baseline --dry-run --status=draft --source-dir=/tmp/fm-help-drafts.nnYsTB --no-ansi
```

Result:

```text
files_found=1
pages_found=12
will_create=12
will_update=0
will_skip=0
dry-run complete
```

## Draft Create

Command:

```text
php artisan content-pages:import-local-baseline --status=draft --source-dir=/tmp/fm-help-drafts.nnYsTB --no-ansi
```

Result:

```text
files_found=1
pages_found=12
will_create=12
will_update=0
will_skip=0
import complete
```

## Created Draft Records

| ID | Locale | Slug | Status | Public | Indexable | Published revision | Published at |
| ---: | --- | --- | --- | --- | --- | --- | --- |
| 31 | zh-CN | `help-unlock-failure` | draft | false | false | null | null |
| 32 | en | `help-unlock-failure` | draft | false | false | null | null |
| 33 | zh-CN | `help-payment-refund` | draft | false | false | null | null |
| 34 | en | `help-payment-refund` | draft | false | false | null | null |
| 35 | zh-CN | `help-result-recovery` | draft | false | false | null | null |
| 36 | en | `help-result-recovery` | draft | false | false | null | null |
| 37 | zh-CN | `help-privacy-data` | draft | false | false | null | null |
| 38 | en | `help-privacy-data` | draft | false | false | null | null |
| 39 | zh-CN | `help-use-boundaries` | draft | false | false | null | null |
| 40 | en | `help-use-boundaries` | draft | false | false | null | null |
| 41 | zh-CN | `help-data-deletion` | draft | false | false | null | null |
| 42 | en | `help-data-deletion` | draft | false | false | null | null |

## Public Surface Checks

All public routes returned 404:

- `/zh/help/unlock-failure`
- `/en/help/unlock-failure`
- `/zh/help/payment-refund`
- `/en/help/payment-refund`
- `/zh/help/result-recovery`
- `/en/help/result-recovery`
- `/zh/help/privacy-data`
- `/en/help/privacy-data`
- `/zh/help/use-boundaries`
- `/en/help/use-boundaries`
- `/zh/help/data-deletion`
- `/en/help/data-deletion`

Enumeration checks:

- `sitemap.xml`: 0 target hits
- `llms.txt`: 0 target hits
- `llms-full.txt`: 0 target hits

## Sidecars

- `ContentPage` does not currently expose a first-class `support_contact` field. The import package records `support_contact=support@fermatmind.com`, but the created CMS draft rows do not have a dedicated support-contact column. PR5/PR6 must verify whether this is acceptable for editorial review or whether a backend field/schema follow-up is required before publish.
- The current production release did not contain the PR3 package files, so this task used a temporary `/tmp` source directory instead of deploying. No deployment was performed.

## Next Task

`HELP-CONTENT-DRAFT-POSTCHECK-01`
