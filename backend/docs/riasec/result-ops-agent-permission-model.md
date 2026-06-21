# RIASEC Result Page Ops Agent Permission Model

Status: `RIASEC-OPS-AGENT-PERMISSION-MODEL-01`

This document defines the permission model for the Holland/RIASEC result page ops agent. It is a governance contract for automation boundaries only. It does not authorize production rollout, production CMS writes, production import, runtime enablement, or frontend fallback content.

## Permission Tiers

### Auto-To-PR

The ops agent may:

- read `docs/codex/pr-train.yaml` and `docs/codex/pr-train-state.json`;
- verify that dependencies are merged into `main`;
- create a scoped branch from latest `main`;
- edit files allowed by the current manifest entry;
- run local checks declared by the current manifest entry;
- run changed-file scope validation;
- commit, push, and open one pull request for the current task;
- poll GitHub checks and inspect failures.

The ops agent may not:

- modify files outside the current manifest scope;
- merge multiple PR scopes into one branch;
- skip a dependency that is not merged into `main`;
- bypass required GitHub checks;
- merge when review or repository policy blocks the PR.

### Auto-To-Staging

The ops agent may:

- run dry-run import, preview, smoke, checksum, inventory, and leak-scan commands against staging-only artifacts;
- write staging reports and staging artifact manifests;
- mark staging outputs with `runtime_use=staging_only`;
- record `production_use_allowed=false`, `ready_for_production=false`, and `cms_write_performed=false`.

The ops agent may not:

- write production CMS data;
- import content into production;
- mutate production environment flags;
- mark generated content as production-ready;
- use staging output as runtime authority before a specific runtime-wrapper PR allows it.

### Auto-To-Report

The ops agent may:

- write go/no-go reports;
- write sidecar blocker records for issues not introduced by the current PR;
- classify failures as current-PR, external dependency, environment, repository policy, or manual approval;
- continue to the next PR only when the current PR has green required checks, clean scope validation, and no current-PR blocker.

The ops agent may not:

- treat a report as production approval;
- suppress a current-PR failure as an external blocker;
- hide required check failures;
- convert sidecar records into production actions.

## Forbidden Capabilities

The following capabilities are always denied unless a later human-approved production SOP explicitly changes the repository policy:

- production rollout execution;
- production CMS write or import;
- production gate enablement;
- production environment or feature-flag mutation;
- frontend-authored RIASEC interpretation fallback;
- publication of private score, raw score, vector, percentile, selector trace, source, QA, editor metadata, attempt id, user id, or private URL fields;
- changing MBTI, Big Five, Enneagram, payment, or account runtime behavior as a side effect of RIASEC ops work.

## Stage Gate Requirements

Every automated task must pass these gates in order:

1. `dependency_merged`
2. `branch_created_from_latest_main`
3. `manifest_scope_loaded`
4. `local_checks_passed`
5. `scope_validation_passed`
6. `pull_request_opened`
7. `github_required_checks_green`
8. `merge_policy_satisfied`
9. `merged_to_main`
10. `main_synced`
11. `task_branch_deleted`
12. `post_merge_revalidated`

The ops agent stops when a current-PR gate fails. If a blocker is external to the current PR, it may be recorded as a sidecar issue only after the current PR has passed required checks and scope validation.

## Audit Evidence

Each PR must preserve enough evidence for review:

- manifest entry and current state entry;
- changed-file allowlist result;
- local command names and pass/fail state;
- GitHub check names and conclusions;
- PR URL and merge commit when available;
- sidecar blocker record when applicable;
- explicit statement that production rollout, production CMS write, and production gate enablement were not performed.
