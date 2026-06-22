# Big Five V2 Live Dry-Run PR Mutation Pilot

Status: `B5-RESULT-LIVE-DRY-RUN-PILOT-01`

This evidence note records a no-production-impact live GitHub mutation pilot for the Big Five Result Page V2 asset agent execution runner. The pilot validated branch creation, commit, push, and PR creation only. It did not merge the created PR.

## Scope

- Source action: `generate-candidates`
- Planning action: `plan-pr`
- Execution action: `execute-github-mutation`
- Live flags used: `--mutation-mode=live --allow-github-mutation`
- GitHub repository: `fermatmind/fap-api`
- Created pilot branch: `codex/big5-v2-live-dry-run-artifact`
- Created pilot PR: `https://github.com/fermatmind/fap-api/pull/2266`
- Pilot PR state at evidence capture: `OPEN`

## Pilot PR Files

The live-created pilot PR is artifact-only and limited to:

- `backend/content_assets/big5/result_page_v2/agent_runs/testing-live-dry-run/live-dry-run-source/candidate_generation_summary.json`
- `backend/content_assets/big5/result_page_v2/agent_runs/testing-live-dry-run/live-dry-run-source/content_asset_candidates.jsonl`
- `backend/content_assets/big5/result_page_v2/agent_runs/testing-live-dry-run/live-dry-run-source/selector_asset_candidates.jsonl`

The pilot PR intentionally remains unmerged for the later live merge-cleanup pilot.

## Execution Summary

The successful live execution produced:

- `preflight_valid=true`
- `blocker_count=0`
- `step_count=8`
- `live_execution_performed=true`
- `git_branch_created=true`
- `git_commit_created=true`
- `github_pr_created=true`
- `github_merge_performed=false`
- `auto_merge_performed=false`
- `remote_branch_deleted=false`
- `local_main_synced=false`

The generated candidate summary stayed non-runtime:

- `ready_for_pilot=false`
- `ready_for_runtime=false`
- `ready_for_production=false`
- `production_use_allowed=false`
- `validation_error_count=0`
- `leak_hit_count=0`

## Operator Notes

An initial attempt using `backend/artifacts/big5_result_page_v2_agent/**` failed closed because `backend/artifacts` is ignored by git and the execution runner does not force-add ignored paths. The successful pilot used `backend/content_assets/big5/result_page_v2/agent_runs/**`, which is a non-runtime artifact/evidence lane and can be staged by the runner without weakening git add policy.

A temporary clean clone was used for the live pilot because the active worktree is a Git worktree whose `.git` path is a file. The runner's current preflight accepts a normal `.git` directory. No production data, CMS content, DB state, runtime flags, import gates, release snapshots, rollout gates, or frontend files were touched.

## Deferred

- The pilot PR was not merged.
- GitHub check polling remains deferred to `B5-RESULT-AUTO-CHECK-POLLER-01`.
- Mechanical fixes remain deferred to `B5-RESULT-MECHANICAL-FIX-APPLY-01`.
- Live merge cleanup remains deferred to `B5-RESULT-AUTO-MERGE-LIVE-PILOT-01`.
