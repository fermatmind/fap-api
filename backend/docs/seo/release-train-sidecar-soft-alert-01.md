# RELEASE-TRAIN-SIDECAR-SOFT-ALERT-01 Report

## 1. Executive Summary

This PR adds an explicit release-train policy for non-core discoverability artifact instability. The policy allows `llms-full`-class artifact read failures to be recorded as sidecar soft alerts only when the manifest opts in and the check is not a core page/API smoke, private or held URL exposure, Search Channel anomaly, staging containment regression, or required GitHub check.

## 2. Hard-Block Rules

The following remain hard-blocking:

- required GitHub checks
- core page/API smoke failures
- sitemap/private URL leakage
- held career slug exposure
- clinical/depression exposure
- Search Channel anomalies
- staging containment regressions
- deploy wrapper failures

## 3. Soft-Alert Boundary

`llms-full` and equivalent GEO/discoverability artifacts may become non-blocking sidecars only when the smoke check sets:

- `surface: llms-full`
- `soft_alert: true`
- `hard_block: false`
- `core_smoke: false`

The manifest item must also set `sidecar_policy.allow_discoverability_artifact_soft_alerts: true`.

## 4. Implementation

The release train now carries smoke-check metadata through `smoke.py`, classifies discoverability artifact failures in `sidecar.py`, and records sidecar payloads from `release_train.py` when an allowed non-core artifact failure occurs.

## 5. Validation

Targeted tests cover:

- `llms-full` timeout as an allowed discoverability sidecar
- private/staging guard failures remaining blocking
- release train `evaluate_item` continuing on allowed `llms-full` sidecar
- release train blocking staging guard failures even when marked soft-alert

## 6. What Was Not Done

- No deploy.
- No rollback.
- No Search Channel enqueue or URL submission.
- No CMS/DB mutation.
- No runtime promotion.
- No weakening of core smoke or private/staging guards.

## 7. Final Decision

release_train_sidecar_soft_alert_completed_ready_for_deploy_readiness

## 8. Next Task

DEPLOY-READINESS｜Deploy CAREER 1046 growth foundation fixes
