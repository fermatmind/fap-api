# Big Five Result Page V2 Agent Execution Runner SOP

Status: `B5-RESULT-RUNNER-SOP-RUNBOOK-01`

This SOP defines the backend-only execution sequence for the Big Five Result Page V2 content asset agent after the GitHub mutation runner was introduced. It does not authorize frontend copy, final `big5_result_page_v2` payload generation, CMS writes, database writes, runtime flag changes, release snapshots, production import gates, rollout gates, or production content changes.

## Authority

The backend remains the content asset authority. Frontend consumers may render backend-provided payloads and fixtures, but they must not create or patch Big Five result page copy.

`big5_report_engine_v2` remains a legacy fallback compatibility fact. It is not the primary content asset path.

Nightly and weekly scheduled jobs do not run live GitHub mutation:

```bash
php artisan big5:result-page-v2-agent audit --strict --json --no-ansi
php artisan big5:result-page-v2-agent weekly-ops --json --no-ansi
```

The only allowed live GitHub mutation path is the explicit execution runner:

```bash
php artisan big5:result-page-v2-agent execute-github-mutation \
  --mutation-mode=live \
  --allow-github-mutation \
  --json --no-ansi
```

## Canonical Sequence

Run each step from the repository root unless the command starts with `cd backend`.

1. Audit current assets:

```bash
cd backend && APP_ENV=testing php artisan big5:result-page-v2-agent audit \
  --run-id=<audit-run> \
  --strict \
  --json --no-ansi
```

2. Generate candidate drafts:

```bash
cd backend && APP_ENV=testing php artisan big5:result-page-v2-agent generate-candidates \
  --run-id=<candidate-run> \
  --artifact-dir=artifacts/big5_result_page_v2_agent/<operator-run> \
  --json --no-ansi
```

3. Stage reviewed candidates only after a human review manifest exists:

```bash
cd backend && APP_ENV=testing php artisan big5:result-page-v2-agent stage-candidates \
  --run-id=<stage-run> \
  --artifact-dir=artifacts/big5_result_page_v2_agent/<operator-run> \
  --candidate-dir=artifacts/big5_result_page_v2_agent/<operator-run>/<candidate-run> \
  --staging-output-dir=content_assets/big5/result_page_v2/staging_candidate_imports/<stage-run> \
  --allow-staging-write \
  --json --no-ansi
```

4. Plan an artifact PR:

```bash
cd backend && APP_ENV=testing php artisan big5:result-page-v2-agent plan-pr \
  --run-id=<plan-run> \
  --artifact-dir=artifacts/big5_result_page_v2_agent/<operator-run> \
  --source-run-dir=artifacts/big5_result_page_v2_agent/<operator-run>/<source-run> \
  --pr-id=<train-or-artifact-pr-id> \
  --branch=codex/<safe-task-branch> \
  --title="<PR title>" \
  --json --no-ansi
```

5. Execute the PR plan in simulate mode first:

```bash
cd backend && APP_ENV=testing php artisan big5:result-page-v2-agent execute-github-mutation \
  --run-id=<execute-simulate-run> \
  --artifact-dir=artifacts/big5_result_page_v2_agent/<operator-run> \
  --execution-plan-json=artifacts/big5_result_page_v2_agent/<operator-run>/<plan-run>/auto_pr_orchestration_plan.json \
  --repo-root=<repo-root> \
  --github-repo=fermatmind/fap-api \
  --mutation-mode=simulate \
  --json --no-ansi
```

6. Execute the PR plan live only when scope validation is green and the operator explicitly intends to create a branch, commit, push, and PR:

```bash
cd backend && APP_ENV=testing php artisan big5:result-page-v2-agent execute-github-mutation \
  --run-id=<execute-live-run> \
  --artifact-dir=artifacts/big5_result_page_v2_agent/<operator-run> \
  --execution-plan-json=artifacts/big5_result_page_v2_agent/<operator-run>/<plan-run>/auto_pr_orchestration_plan.json \
  --repo-root=<repo-root> \
  --github-repo=fermatmind/fap-api \
  --mutation-mode=live \
  --allow-github-mutation \
  --json --no-ansi
```

7. Inspect checks from an exported PR state or status rollup artifact:

```bash
cd backend && APP_ENV=testing php artisan big5:result-page-v2-agent inspect-ci \
  --run-id=<ci-inspection-run> \
  --artifact-dir=artifacts/big5_result_page_v2_agent/<operator-run> \
  --checks-json=<status-check-rollup-json> \
  --json --no-ansi
```

8. Plan merge cleanup after GitHub required checks are green:

```bash
cd backend && APP_ENV=testing php artisan big5:result-page-v2-agent plan-merge-cleanup \
  --run-id=<merge-plan-run> \
  --artifact-dir=artifacts/big5_result_page_v2_agent/<operator-run> \
  --pr-state-json=<green-pr-state-json> \
  --json --no-ansi
```

9. Execute merge cleanup in simulate mode first:

```bash
cd backend && APP_ENV=testing php artisan big5:result-page-v2-agent execute-github-mutation \
  --run-id=<merge-simulate-run> \
  --artifact-dir=artifacts/big5_result_page_v2_agent/<operator-run> \
  --execution-plan-json=artifacts/big5_result_page_v2_agent/<operator-run>/<merge-plan-run>/auto_merge_cleanup_plan.json \
  --repo-root=<repo-root> \
  --github-repo=fermatmind/fap-api \
  --mutation-mode=simulate \
  --json --no-ansi
```

10. Execute merge cleanup live only when the merge plan says `gate.can_merge=true`, required checks are green, scope validation is green, and the PR is artifact-only or otherwise explicitly safe:

```bash
cd backend && APP_ENV=testing php artisan big5:result-page-v2-agent execute-github-mutation \
  --run-id=<merge-live-run> \
  --artifact-dir=artifacts/big5_result_page_v2_agent/<operator-run> \
  --execution-plan-json=artifacts/big5_result_page_v2_agent/<operator-run>/<merge-plan-run>/auto_merge_cleanup_plan.json \
  --repo-root=<repo-root> \
  --github-repo=fermatmind/fap-api \
  --mutation-mode=live \
  --allow-github-mutation \
  --json --no-ansi
```

## Redaction

Artifacts and PR bodies must not include private URLs, real attempt identifiers, PDF files, raw report bodies, raw scores, domain or facet vectors, shareable percentiles, internal metadata, credentials, local absolute paths, or raw GitHub tokens.

When evidence needs a path, use placeholders such as `<repo-root>`, `<backend>`, `<artifact-run>`, and `<pr-number>`.

## Artifact Retention

Operator runs may keep redacted JSON and Markdown evidence under `backend/artifacts/big5_result_page_v2_agent/<run-id>/` or a reviewed `backend/content_assets/big5/result_page_v2/agent_runs/<run-id>/` package.

Do not commit temporary smoke artifacts unless the active PR explicitly allows artifact evidence. Always remove `testing-*` runs before commit unless the PR scope says they are intentional evidence.

## Sidecar Issues

If a blocker is not introduced by the current PR, GitHub required checks are green, and scope validation is green, record a sidecar issue or follow-up note and continue the train.

Sidecar records must include:

- observed blocker;
- why it is outside the current PR scope;
- evidence artifact or check name;
- owner or next PR lane;
- whether the current PR was allowed to continue.

## Failure Attribution

Classify failures before fixing:

- `current_pr_scope`: introduced by files changed in the current PR; inspect and fix before continuing.
- `external_blocker`: unrelated system, dependency, or existing failure; record sidecar if required checks and scope validation are green.
- `operator_input_missing`: missing review manifest, PR state export, check rollup, or live authorization; stop until provided.
- `policy_block`: production gate, runtime flag, frontend copy, CMS write, DB write, or unsafe payload request; stop and do not work around it.

## Stop Conditions

Stop immediately when:

- worktree dirt overlaps the current PR scope;
- a dependency PR is not merged into `main`;
- local validation fails;
- changed files exceed the declared allowed paths;
- required GitHub checks fail;
- merge state is not clean;
- a live command would touch production/import/rollout/runtime gates;
- a live command would run without `--mutation-mode=live --allow-github-mutation`;
- a report contains private URL, attempt id, raw payload, raw scores, shareable percentiles, internal metadata, or credentials.

## Post-Merge Cleanup

After each merged PR:

```bash
git fetch origin --prune
git merge-base --is-ancestor <merge-commit> origin/main
git switch main
git pull --ff-only origin main
test "$(git rev-parse main)" = "$(git rev-parse origin/main)"
git status --porcelain=v1
git branch -d <task-branch>
git ls-remote --heads origin <task-branch>
```

Delete the remote branch only when it is the merged PR head branch and cleanup is allowed. Finish on clean synced `main` before starting the next PR.
