# RIASEC Result Page Ops Agent Runbook

Status: `RIASEC-OPS-AGENT-RUNBOOK-01`

This runbook defines the operating contract for the Holland/RIASEC result page ops agent. It authorizes auto-to-PR, auto-to-staging, and auto-to-report workflows. It does not authorize automatic production rollout, production CMS writes, production import, or production gate enablement.

## Operating Boundary

The ops agent may automate:

- creating scoped PR branches from latest `main`;
- validating PR-train manifest and state entries;
- running local checks and scope validation;
- opening pull requests and polling required GitHub checks;
- producing staging-only dry-run artifacts and preview reports;
- writing go/no-go reports and sidecar issues for blockers not introduced by the current PR.

The ops agent must not automate:

- production rollout execution;
- production CMS writes or imports;
- production gate enablement;
- production environment flag mutation;
- frontend-authored RIASEC interpretation fallback;
- public release of private score, raw score, vector, percentile, selector trace, source, QA, or editor metadata.

Production may be prepared only as a manual approval gate with validation evidence, rollback or kill-switch instructions, and post-deploy smoke procedures.

## Required Sequence

The program is split into fourteen PR-train tasks:

1. `RIASEC-OPS-AGENT-RUNBOOK-01`: document the ops-agent boundary, stage gates, and forbidden actions.
2. `RIASEC-OPS-AGENT-PERMISSION-MODEL-01`: define the permission model for auto-to-PR, auto-to-staging, and auto-to-report.
3. `RIASEC-OPS-AGENT-PR-TRAIN-ORCHESTRATOR-01`: implement the PR-train orchestration wrapper.
4. `RIASEC-OPS-AGENT-STAGING-RUNNER-01`: implement staging dry-run, preview, and smoke runner support.
5. `RIASEC-OPS-AGENT-REPORTING-SIDECAR-01`: implement go/no-go and sidecar issue reporting.
6. `RIASEC-RESULT-FAPWEB-RENDERED-PREVIEW-QA-01`: hand backend fixtures to fap-web rendered preview QA.
7. `RIASEC-RESULT-BACKEND-STAGING-IMPORT-HANDOFF-01`: create backend staging import handoff governance.
8. `RIASEC-RESULT-SELECTOR-COVERAGE-BATCH-01`: fill route-specific selector coverage gaps.
9. `RIASEC-RESULT-STAGING-IMPORT-DRY-RUN-01`: run staging import dry-run validation.
10. `RIASEC-RESULT-RUNTIME-WRAPPER-STAGING-01`: add staging-gated runtime wrapper wiring.
11. `RIASEC-RESULT-PILOT-ALLOWLIST-GATE-01`: add pilot allowlist and kill-switch controls.
12. `RIASEC-RESULT-ALL-SURFACE-PILOT-QA-01`: validate all pilot surfaces.
13. `RIASEC-RESULT-PRODUCTION-IMPORT-GATE-01`: define production import gate and release evidence.
14. `RIASEC-RESULT-PRODUCTION-ROLLOUT-GATE-01`: define manual production rollout gate, rollback, and smoke procedures.

Each task is one PR scope. A task must not implement future PR behavior before its dependency has merged into `main`.

## Stage Gates

The allowed stage progression is:

1. `manifest_authorized`
2. `branch_created`
3. `local_checks_passed`
4. `scope_validation_passed`
5. `pull_request_open`
6. `github_required_checks_green`
7. `merged`
8. `main_synced`
9. `branch_cleaned`
10. `post_merge_revalidated`

Staging dry-runs may produce reports and artifacts only under staging paths. A staging artifact must keep:

- `runtime_use=staging_only`;
- `production_use_allowed=false`;
- `ready_for_runtime=false` until an explicit runtime PR changes only the staging wrapper;
- `ready_for_production=false`;
- `cms_write_performed=false`;
- `runtime_change_performed=false` unless the current runtime-wrapper PR explicitly permits it.

## Sidecar Policy

If a blocker is not introduced by the current PR and the current PR has green required checks plus clean scope validation, the ops agent records the blocker as a sidecar issue and continues the train. Sidecar records must include:

- blocker title;
- introduced-by-current-PR flag;
- evidence path or command output summary;
- severity;
- whether it blocks current merge;
- recommended follow-up PR or manual action.

Current-PR failures still block progression.

## Stop Conditions

Stop immediately when:

- local checks for the current PR fail;
- GitHub required checks fail;
- changed files drift outside the manifest scope;
- a dependency is not merged into `main`;
- the worktree cannot isolate the current scope;
- production rollout, production CMS write, or production gate enablement would be required to proceed;
- any public payload contains private score, raw score, vector, percentile, selector trace, source, QA, editor, internal metadata, attempt id, user id, or private URL fields.

## Production Rule

Production rollout remains manual. The final production rollout gate may verify configuration, approval evidence, rollback readiness, kill-switch behavior, and post-deploy smoke procedures, but it must not execute the production rollout or mutate production configuration automatically.
