# MBTI64 Backend Import Contract Patch 01

Date: 2026-06-18

Status: PASS

This PR is backend contract-only. It does not import CMS rows, publish pages,
change sitemap/LLMS generation, or submit search surfaces.

## Source Package

- Input artifact: `mbti64-content-package-pilot-v2.1.json`
- Rows validated: 8
- Variant rows: 6
- Comparison rows: 2
- Row order: locked to the V2.1 8-page pilot queue
- Generated contract artifact:
  `backend/docs/seo/generated/mbti64-backend-import-contract-patch-2026-06-18.json`

## CMS Draft Mapping

### Variant Pages

Variant pages map to `PersonalityProfileVariantRevision` draft snapshots:

- Lookup identity: `org_id=0 + MBTI + locale + runtime_type_code`
- Snapshot key: `mbti64_variant_content_package_v2_1`
- Future promotion targets:
  - `personality_profile_variant_sections`
  - `personality_profile_variant_seo_meta`

The current PR does not create revisions and does not promote live sections or
SEO metadata.

### Comparison Pages

Comparison pages do not get a new standalone backend model in this PR.

They map to a base `PersonalityProfileRevision` draft overlay:

- Lookup identity: `org_id=0 + MBTI + locale + canonical_type_code`
- Snapshot key: `mbti64_comparison_draft_v2_1`
- Example: `/en/personality/intj-a-vs-intj-t` attaches to the `INTJ` base
  profile revision as comparison draft metadata.

A later runtime/API PR must explicitly consume this overlay before any
comparison page can be published or search-released.

## Field Policy

Variant first-class promotion candidates:

- `url`
- `locale`
- `page_type`
- `canonical_target`
- `seo.seo_title`
- `seo.seo_description`
- `seo.breadcrumb_title`
- `seo.h1`
- `seo.quick_answer_summary`
- `content`
- `faq`
- `internal_links`

Structured metadata fields:

- `primary_query`
- `secondary_queries`
- `excluded_queries`
- `target_intent`
- `target_test_route`
- `method_boundary`
- `trademark_boundary`
- `information_gain`
- `claim_risk_notes`
- `qa_flags_for_codex`
- `route_safety`
- `v2_optimization`
- `above_the_fold_module`
- `serp_ctr_package_v2`
- `status`

Unsupported fields are not dropped and are not silently mapped to unrelated
columns. They remain under the row revision snapshot structured metadata until
a later migration/model/API PR promotes them to first-class fields.

## Write Guard

The command introduced by this PR is:

```bash
php artisan personality:mbti64-backend-import-contract \
  --package=/path/to/mbti64-content-package-pilot-v2.1.json \
  --dry-run \
  --json
```

Safety behavior:

- `--dry-run` is required.
- `--write` is intentionally unsupported and fails closed.
- `writes_committed=false`
- `publish_attempted=false`
- `search_release_attempted=false`
- `sitemap_llms_release_attempted=false`
- no `published_at` mutation
- no public/indexable/sitemap/llms flags enabled

A later real write/import PR must require a separate operator-approved command
with explicit draft-only and no-release flags.

## Validation

Commands run:

```bash
php -l backend/app/Services/Cms/Mbti64BackendImportContractPlanner.php
php -l backend/app/Console/Commands/PersonalityMbti64BackendImportContract.php
php -l backend/tests/Feature/Console/PersonalityMbti64BackendImportContractCommandTest.php
php artisan test tests/Feature/Console/PersonalityMbti64BackendImportContractCommandTest.php --display-warnings
php artisan personality:mbti64-backend-import-contract --package=/Users/rainie/Desktop/mbti64-content-package-pilot-v2.1.json --dry-run --json --output=docs/seo/generated/mbti64-backend-import-contract-patch-2026-06-18.json
```

Result:

- Contract status: `pass`
- Errors: 0
- Warnings: 0
- CMS writes: 0
- Publish/search release: 0
