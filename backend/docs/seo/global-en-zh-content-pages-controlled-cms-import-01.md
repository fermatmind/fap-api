# GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-CMS-IMPORT-01 Report

## Executive Summary
The exact approval phrase was present. The official CMS importer `content-pages:import-local-baseline` was used with dry-run first, `--status=draft`, and no `--upsert`.

Dry-run passed with `will_create=5`, `will_update=0`, and `will_skip=6`. Controlled import then created five draft-only English content pages: brand, charter, foundation, careers, and policies.

Six scoped pages already existed as published English CMS records before this task, so they were skipped without mutation: about, help-about, help-contact, help-faq, help-for-business-and-research, and method-boundaries.

## Scope
Wave 1 content/help/policy pages only. Articles, topics, test landing pages, research, career jobs, career recommendations, result/report assets, media assets, UI runtime, Search Channel, sitemap/llms/footer exposure, publish, deploy, and pSEO were not touched.

## Preflight
- Import package parsed: pass
- Human-review decision packet parsed: pass
- Official import runtime available: `content-pages:import-local-baseline`
- Blocked/deferred items excluded: pass
- Out-of-scope items excluded: privacy, terms
- Publish disabled: pass
- Sitemap/llms/footer/Search Channel exposure disabled: pass
- Upsert disabled to protect existing published records: pass

## Dry-Run Result
```text
baseline_source_dir=/tmp/fap-content-pages-controlled-cms-import-01-20260527074715
dry_run=1
upsert=0
status_mode=draft
files_found=1
pages_found=11
will_create=5
will_update=0
will_skip=6
dry-run complete
```

## Controlled Import Result
```text
baseline_source_dir=/tmp/fap-content-pages-controlled-cms-import-01-20260527074715
dry_run=0
upsert=0
status_mode=draft
files_found=1
pages_found=11
will_create=5
will_update=0
will_skip=6
import complete
```

## Created Draft Records
- brand: draft, non-public, non-indexable
- charter: draft, non-public, non-indexable
- foundation: draft, non-public, non-indexable
- careers: draft, non-public, non-indexable
- policies: draft, non-public, non-indexable

## Skipped Existing Records
These were already existing English published CMS records and were not mutated because `--upsert` was not used:
- about
- help-about
- help-contact
- help-faq
- help-for-business-and-research
- method-boundaries

## Not Imported
- support: blocked/deferred because the source package marks it `deferred_missing_authority`
- privacy: out of this approval scope
- terms: out of this approval scope

## Post-Import Verification
New draft public runtime checks returned non-200:
- /en/brand: 404
- /en/charter: 404
- /en/foundation: 404
- /en/careers: 404
- /en/policies: 404

No Search Channel command was run. No URL submission was run. No deployment was run.

## Sidecars
- Existing published English records were skipped and should be handled only by a later explicit review/update approval.
- Support remains blocked until a dedicated support authority source exists.
- Privacy and terms remain outside this import scope.

## Validation
- `php artisan test --filter=GlobalEnZhContentPagesControlledCmsImport01 --no-ansi`: passed, 1 test / 69 assertions
- `php artisan route:list --no-ansi`: passed, 203 routes listed
- `vendor/bin/pint --test`: passed, 3580 files
- `composer validate --strict`: passed
- `composer audit --locked --no-interaction --ignore-unreachable`: passed, no advisories
- JSON/YAML parse: passed
- `git diff --check && git diff --cached --check`: passed
- fap-web reference status: clean

## Final Decision
`content_pages_controlled_cms_import_completed_with_sidecars`

## Next Task
`GLOBAL-EN-ZH-CONTENT-PAGES-IMPORT-VERIFY-01`
