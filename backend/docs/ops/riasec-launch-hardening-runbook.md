# RIASEC Launch Hardening Runbook

This runbook is the backend release authority for launching RIASEC as one flagship scale with two public forms:

- default public form: `riasec_60`
- enhanced public form: `riasec_140`
- canonical public slug: `holland-career-interest-test-riasec`

It does not authorize scorer changes, new product surfaces, frontend fallback behavior, or restoration of the legacy 36Q product.

## Stop-ship rules

Stop the backend release if any of these are true:

- `/api/v0.3/scales/lookup?slug=holland-career-interest-test-riasec` does not expose both `riasec_60` and `riasec_140`.
- `/api/v0.3/scales/catalog` does not include the canonical RIASEC item and both forms.
- Either questions endpoint returns the wrong count: `riasec_60` must return 60 items and `riasec_140` must return 140 items.
- Any backend authority response or baseline still exposes the old career RIASEC route, old 36Q copy, or old 36Q form code.
- RIASEC result, report, report-access, share, or me-attempts tests fail.
- The MBTI / Big Five / Enneagram regression chain fails.

## Pre-release local checks

Run from `/Users/rainie/Desktop/GitHub/fap-api/backend`:

```bash
php artisan test --filter 'RiasecAssessmentFlowTest|RiasecScorerTest|ScalesLookupTest|ShareSummaryContractTest|AttemptReportAccessReadTest|LandingSurfacePublicApiTest|ContentProbeServiceTest'
bash scripts/ci_verify_mbti.sh
```

Run from `/Users/rainie/Desktop/GitHub/fap-api`:

```bash
LEGACY_ROUTE='career/tests/ria''sec'
LEGACY_COPY_EN='36 question''s'
LEGACY_COPY_ZH='36 ''题'
LEGACY_FORM='riasec_''36'
LEGACY_STORAGE='fm_career_riasec_''v1'
LEGACY_RE="${LEGACY_ROUTE}|${LEGACY_COPY_EN}|${LEGACY_COPY_ZH}|${LEGACY_FORM}|${LEGACY_STORAGE}"
rg -n "${LEGACY_RE}" . \
  -g '!vendor' \
  -g '!backend/vendor' \
  -g '!node_modules' \
  -g '!.git' \
  -g '!backend/storage' \
  -g '!storage' \
  -g '!backend/artifacts'
```

The grep command should return no live backend authority references. Historical docs may be reviewed case by case, but runtime baseline, registry, content pack, route, seed, test fixture, or smoke references are blockers.

## Seed and publish checks

Before deploy, confirm the registry and CMS baselines are seedable:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan fap:scales:seed-default
php artisan fap:scales:sync-slugs
php artisan landing-surfaces:import-local-baseline \
  --upsert \
  --status=published \
  --source-dir=../content_baselines/landing_surfaces
```

The active registry must map:

- `RIASEC` -> `HOLLAND_RIASEC_CAREER_INTEREST`
- primary slug -> `holland-career-interest-test-riasec`
- default form -> `riasec_60`
- supported forms -> `riasec_60`, `riasec_140`

The landing baselines must use `/tests/holland-career-interest-test-riasec` and may expose form-specific take links with `?form=riasec_60` and `?form=riasec_140`.

## Content pack probe expectations

The publish probe must be scale-aware. For RIASEC, it must probe:

- health: `/api/healthz`
- questions: `/api/v0.3/scales/RIASEC/questions`
- lookup: `/api/v0.3/scales/lookup?slug=holland-career-interest-test-riasec`

It must not probe MBTI questions as a hardcoded fallback for RIASEC.

Pack manifests must remain aligned with the flagship form model:

- `backend/content_packs/RIASEC/v1-standard-60/compiled/manifest.json`
  - `form_code=riasec_60`
  - `default_form_code=riasec_60`
  - `supported_forms=["riasec_60","riasec_140"]`
- `backend/content_packs/RIASEC/v1-enhanced-140/compiled/manifest.json`
  - `form_code=riasec_140`
  - `default_form_code=riasec_60`
  - `supported_forms=["riasec_60","riasec_140"]`

## Staging or production smoke

After deploy, run the read-only launch smoke from `/var/www/fap-api/current/backend` or from a local checkout:

```bash
BASE_URL=https://api.fermatmind.com \
LOCALE=zh-CN \
REGION=CN_MAINLAND \
bash backend/scripts/verify_riasec_launch.sh
```

If running from `/var/www/fap-api/current/backend`, use:

```bash
BASE_URL=https://api.fermatmind.com \
LOCALE=zh-CN \
REGION=CN_MAINLAND \
bash scripts/verify_riasec_launch.sh
```

This smoke verifies:

- health
- lookup forms
- catalog forms
- 60Q question delivery
- 140Q question delivery
- landing surface canonical links
- absence of the legacy 36Q public route/copy in the tested landing payload

The full authenticated lifecycle is covered by `RiasecAssessmentFlowTest`; do not replace it with a frontend or manual fallback.

## Post-deploy server checks

Run on the backend server:

```bash
cd /var/www/fap-api/current/backend

php artisan fap:schema:verify
php artisan ops:healthz-snapshot
php artisan release:verify-public-content \
  --content-source-dir=/var/www/fap-api/current/content_baselines/content_pages \
  --no-interaction \
  --ansi

php artisan route:list | grep -E 'api/v0\.5/landing-surfaces|api/v0\.3/scales/lookup|api/v0\.3/scales/.*/questions|api/v0\.3/attempts/.*/report-access'
```

`release:verify-public-content` hard-fails missing backend content pages. Career dataset and job-list completeness are warning-only unless the operator reruns with `--strict-career` or deploys with `DEPLOY_PUBLIC_CONTENT_STRICT_CAREER=1`.

Then run:

```bash
BASE_URL=https://api.fermatmind.com bash scripts/verify_riasec_launch.sh
```

## Evidence to retain

Retain these outputs for launch sign-off:

- git SHA deployed
- release name
- targeted RIASEC PHPUnit command output
- `scripts/ci_verify_mbti.sh` output
- legacy 36Q grep output
- `verify_riasec_launch.sh` output for staging and production
- relevant `content_pack_releases` probe result when a RIASEC pack publish is executed

## Rollback notes

RIASEC launch hardening does not add a DB migration. Normal deploy rollback is therefore the primary code rollback path:

```bash
cd /var/www/fap-api
ls -1 releases | tail -n 10
TARGET_RELEASE="<previous_release>"
test -d "/var/www/fap-api/releases/${TARGET_RELEASE}"
ln -nfs "/var/www/fap-api/releases/${TARGET_RELEASE}" /var/www/fap-api/current
systemctl reload php8.4-fpm
systemctl reload nginx
```

If the issue is only a content pack publish problem, use the existing content release rollback flow for the affected content pack release and rerun the RIASEC smoke. Do not restore the old career RIASEC route, old 36Q form code, or local 36Q fallback content as a rollback tactic.

After any rollback, rerun:

```bash
cd /var/www/fap-api/current/backend
php artisan fap:schema:verify
BASE_URL=https://api.fermatmind.com bash scripts/verify_riasec_launch.sh
```
