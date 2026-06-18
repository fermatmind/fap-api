# MBTI64-CMS-REVISION-DRAFT-01

## Summary

Status: pass.

This artifact records the local validation for the controlled MBTI64 V2.1 CMS revision draft writer. The PR adds backend capability only. It does not run production import, publish content, enable indexing, update sitemap, update llms, trigger search release, or change frontend/runtime/result behavior.

## Source Package

- Package: `docs/seo/personality/content-packages/pilot-v2.1/mbti64-content-package-pilot-v2.1.json`
- Expected rows: 8
- Variant rows: 6
- Comparison rows: 2

## Command Contract

New command:

```bash
php artisan personality:mbti64-cms-revision-draft --package=<path> --dry-run --json
```

Write mode is fail-closed unless all safety flags are present:

```bash
php artisan personality:mbti64-cms-revision-draft \
  --package=<path> \
  --write \
  --draft-only \
  --no-publish \
  --no-index \
  --no-sitemap \
  --no-llms \
  --no-search-release \
  --operator-approved=MBTI64-CMS-REVISION-DRAFT-01 \
  --json
```

## Storage Contract

- Variant rows write draft revisions to `PersonalityProfileVariantRevision`.
- Comparison rows write draft overlays to `PersonalityProfileRevision`.
- Comparison pages do not create a new standalone comparison model or table in this PR.

Snapshot keys:

- Variant: `mbti64_variant_content_package_v2_1`
- Comparison: `mbti64_comparison_draft_v2_1`

Each snapshot stores the source package hash, row identity, first-class draft fields, structured metadata, safety holds, and the raw row.

## Safety Boundary

This PR only creates revision rows in explicit write mode. It does not mutate:

- `personality_profiles`
- `personality_profile_variants`
- section tables
- SEO metadata tables
- publish state
- indexability state
- sitemap or llms state
- search release queues

The command refuses unsafe write attempts. Missing targets fail the batch before any revision row is written.

## Validation

Passed:

```bash
php -l backend/app/Console/Commands/PersonalityMbti64CmsRevisionDraft.php
php -l backend/app/Services/Cms/Mbti64CmsRevisionDraftWriter.php
php -l backend/tests/Feature/Console/PersonalityMbti64CmsRevisionDraftCommandTest.php
```

Passed:

```bash
cd backend
php artisan test tests/Feature/Console/PersonalityMbti64BackendImportContractCommandTest.php tests/Feature/Console/PersonalityMbti64CmsRevisionDraftCommandTest.php --display-warnings
```

Result: 11 tests, 98 assertions.

Passed:

```bash
bash backend/scripts/ci_verify_mbti.sh
```

Passed after remote-check reproduction:

```bash
cd backend
bash scripts/ci/verify_big5_norms.sh
php -d memory_limit=1024M artisan content:lint --pack=BIG5_OCEAN --pack-version=v1
php -d memory_limit=1024M artisan content:compile --pack=BIG5_OCEAN --pack-version=v1
php -d memory_limit=1024M artisan test --filter '(BigFive|Big5|NonMbtiReportContractRegressionTest)' --no-ansi
```

Result: 870 tests, 61,493 assertions.

Remote `verify-bigfive` initially failed because the Big Five runtime freeze classifier treated the new MBTI64 backend command/service registration as runtime-impacting. The follow-up patch adds explicit classifier coverage for the MBTI64 CMS revision draft command/service only. It does not change Big Five result page runtime behavior.

Note: probing the command against the unseeded default local DB returns `target_not_found` for all 8 rows and commits 0 writes. That is expected fail-closed behavior. The seeded sqlite feature tests verify the successful dry-run, write, idempotence, safety-flag, missing-target rollback, comparison-storage, live-field unchanged, and forbidden-route paths.

## Deferred

- No production import.
- No publish.
- No sitemap or llms release.
- No search release.
- No frontend rendering change.
- No result page or scoring change.

Production CMS draft revision writes require a separate explicit operator approval with the deployed SHA and exact command.
