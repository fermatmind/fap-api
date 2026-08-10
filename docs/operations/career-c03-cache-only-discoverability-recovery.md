# Career C03 incident closeout and cache-only discoverability recovery

## Purpose

This control closes the exact failed production run `31373985399` and, only when machine verification proves it necessary, repairs allowlisted Career cache and derived discoverability state. It is not an application deployment path.

The workflow is `.github/workflows/career-c03-cache-only-discoverability-recovery.yml`. Every invocation must run from the exact latest `main` SHA and bind the unchanged active revision. The runner is streamed to the active release and is not installed into the active release.

## Fixed sequence

### 1. `incident_closeout`

Run with only:

```bash
gh workflow run career-c03-cache-only-discoverability-recovery.yml \
  --ref main \
  -f mode=incident_closeout \
  -f expected_control_plane_sha=<exact-latest-main-sha> \
  -f expected_active_revision=40020ab7ef269ee56ce597e9f2fd2fbb99e83549
```

The phase verifies the exact failed-run timing, progress and misleading incident artifacts, the exact C02 PASS artifact, the active release, migration/table facts, deploy lock/process absence, current Career caches and public surfaces. Its success receipt is `PASS_INCIDENT_CLOSED`; that receipt explicitly supersedes the old incident artifact without rewriting it.

Record the successful run id, run attempt, receipt SHA-256 and Actions artifact digest before continuing.

### 2. `verify`

```bash
gh workflow run career-c03-cache-only-discoverability-recovery.yml \
  --ref main \
  -f mode=verify \
  -f expected_control_plane_sha=<same-exact-latest-main-sha> \
  -f expected_active_revision=40020ab7ef269ee56ce597e9f2fd2fbb99e83549 \
  -f incident_closeout_run_id=<run-id> \
  -f incident_closeout_run_attempt=<attempt> \
  -f expected_incident_closeout_receipt_sha256=<receipt-sha256> \
  -f expected_incident_closeout_artifact_digest=<sha256:artifact-digest>
```

The authority inventory and current published cohort are derived from the exact C02 authority artifact; no inventory or cohort counts are hardcoded. The phase compares exact bilingual Career sets for authority, detail coverage, jobs, directory, sitemap source, sitemap, `llms.txt` and `llms-full.txt`, plus two bounded public detail readback rounds.

- `PASS_C03_REVERIFIED_NO_APPLY_REQUIRED`: C03 is PASS with zero production writes. Do not run `apply`.
- `PASS_RECOVERY_REQUIRED`: C03 remains HOLD. Copy the exact one-time approval phrase and receipt lineage into `apply`.
- Any HOLD status: stop. C03 remains HOLD and automatic retry is forbidden.

### 3. `apply` only when required

Use only the exact inputs emitted and bound by the `PASS_RECOVERY_REQUIRED` receipt:

```bash
gh workflow run career-c03-cache-only-discoverability-recovery.yml \
  --ref main \
  -f mode=apply \
  -f expected_control_plane_sha=<same-exact-latest-main-sha> \
  -f expected_active_revision=40020ab7ef269ee56ce597e9f2fd2fbb99e83549 \
  -f incident_closeout_run_id=<incident-run-id> \
  -f incident_closeout_run_attempt=<incident-attempt> \
  -f expected_incident_closeout_receipt_sha256=<incident-receipt-sha256> \
  -f expected_incident_closeout_artifact_digest=<sha256:incident-artifact-digest> \
  -f verify_run_id=<verify-run-id> \
  -f verify_run_attempt=<verify-attempt> \
  -f expected_verify_receipt_sha256=<verify-receipt-sha256> \
  -f expected_verify_artifact_digest=<sha256:verify-artifact-digest> \
  -f operator_approval_phrase='<exact phrase from verify receipt>'
```

Before its first cache write, `apply` verifies the current pre-state SHA, backs up every allowlisted cache key and version pointer, and verifies the backup SHA. It can repair at most 250 current-authority detail targets, rebuild only the EN/ZH directory, refresh the six derived sitemap/llms cache keys, and revalidate the five shared paths plus repaired detail paths. Public concurrency is capped at two.

Success is only `PASS_C03_RECOVERED`. A failure restores the exact backup, revalidates, and compares the recovered state with the verify receipt pre-state. `HOLD_ROLLBACK_INCOMPLETE` forbids automatic retry.

## Negative boundary

This control must not:

- invoke the standard deployment workflow, Deployer or `deploy.php`;
- run migrations or migration rollback;
- create a release, change the active symlink, restart PHP-FPM/Nginx or reload queues;
- write CMS/database authority, publication, eligibility, indexability or Career topic-edge state;
- flush non-allowlisted caches, crawl pages as a warming mechanism, or submit sitemap/llms/Search Channel URLs.

The shared sitemap/llms refresh is accepted only when the exact non-Career URL set SHA remains unchanged. `CAREER_LINK_PUBLICATION_GATE` remains `CLOSED` after C03 PASS. PR5/C06 is a later independent scope and must not start before one of the two final C03 PASS receipts exists.

## Local acceptance

Run from the repository root:

```bash
cd backend
php artisan test \
  tests/Sre/CareerC03CacheOnlyDiscoverabilityRecoveryWorkflowTest.php \
  tests/Sre/CareerC03CacheOnlyDiscoverabilityControlTest.php \
  --no-ansi
vendor/bin/pint --test \
  scripts/operations/career_c03_cache_only_discoverability_control.php \
  tests/Sre/CareerC03CacheOnlyDiscoverabilityRecoveryWorkflowTest.php \
  tests/Sre/CareerC03CacheOnlyDiscoverabilityControlTest.php
php -l scripts/operations/career_c03_cache_only_discoverability_control.php
composer validate --strict
```

From the repository root, also parse the workflow YAML, validate the exact five-file scope, and run `git diff --check`.

## Repository rule impact

This adds a one-incident control-plane recovery entry only. Career content, publication and public API authority remain backend/CMS-authoritative. There is no frontend fallback, runtime authority change, deployment change, migration change, or PR-train manifest/state change.
