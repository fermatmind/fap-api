# Storage Runtime Governance Closure

## Purpose

This document records the completed local storage incident response and the follow-up prevention controls for `backend/storage/app/private/content_releases`.

It is intended for future scans, SRE diagnosis, and backend development work that touches Laravel runtime storage, content release roots, source packaging, release hygiene, or cleanup planning.

## Executive summary

The storage expansion and local deletion incident have been closed at the repository-control level.

Completed controls:

```text
#1450 storage-release-hygiene-guard
#1453 storage-local-retention-sop
#1454 storage-release-roots-audit
#1455 docs-shell-safety-guard
```

These PRs created four guardrails:

```text
1. Prevent content_releases from entering source zip, release package, and artifact-clean flows.
2. Document local retention and storage cleanup SOPs.
3. Add a read-only release roots audit command before any future cleanup discussion.
4. Add docs shell safety checks for unsafe heredoc and destructive Markdown examples.
```

Current operating rule:

```text
audit first
cleanup never first
```

## Incident and recovery summary

A local runtime storage deletion incident occurred during ledger writing. The cause was unsafe shell heredoc usage while writing Markdown content that contained code fences and command examples.

Impact boundary:

```text
Git tracked repo: unaffected
origin/main and merged PRs: unaffected
production: unaffected based on available evidence
local runtime storage history: lost and accepted
current local development chain: revalidated
```

Recovery outcome:

```text
Historical local content release roots were not restored.
Local SQLite scans showed no content_releases DB references.
The empty content_releases path was accepted and later repopulated by normal CI/test flows.
route:list and ci_verify_mbti.sh passed after recovery.
Worktree returned to clean state.
```

The incident was local-only. It was not a Laravel retention logic failure, not a Git tracked source loss, and not a production incident.

## Governance PR chain

### #1450 storage-release-hygiene-guard

Purpose:

```text
Prevent backend/storage/app/private/content_releases from entering source zip, release package, or artifact-clean blind spots.
```

Scope:

```text
release hygiene scripts
source zip verification
security artifact clean checks
release-pack contract tests
docs updates
```

Explicit non-goals:

```text
No storage retention behavior changes.
No content release publish, rollback, rehydrate, or quarantine logic changes.
No local storage deletion.
```

### #1453 storage-local-retention-sop

Purpose:

```text
Document local developer practices for avoiding backend/storage growth and VS Code scanning pressure.
```

Covered guidance:

```text
content_releases is Laravel runtime storage, not source code
VS Code watcher/search exclusions for runtime storage
local .env retention suggestions
storage:prune dry-run warning because it writes prune plans
current_pack quarantine SOP
source_pack and previous_pack review warnings
```

Important boundary:

```text
Production and staging retention defaults were not shortened.
```

### #1454 storage-release-roots-audit

Purpose:

```text
Add a strictly read-only audit command for content release roots.
```

Command:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan storage:release-roots:audit --format=json
```

Safety envelope in expected output:

```text
cleanup_executed=false
plan_file_written=false
db_mutated=false
prune_invoked=false
delete_move_quarantine_options_available=false
```

Classification model:

```text
strong_keep
dangling_ref_repair_required
unreferenced_current_pack_low_risk_candidate
unreferenced_previous_pack_review_required
unreferenced_source_pack_review_required
unknown_shape_review_required
root_missing_no_action
root_empty_no_action
```

Important interpretation:

```text
unreferenced_current_pack_low_risk_candidate is only a review candidate.
unreferenced_source_pack_review_required is not a safe-delete label.
unreferenced_previous_pack_review_required is not a safe-delete label.
unknown_shape_review_required requires investigation before any action.
dangling_ref_repair_required blocks cleanup and requires repair analysis.
```

### #1455 docs-shell-safety-guard

Purpose:

```text
Prevent recurrence of unsafe Markdown or ledger-writing patterns that can execute shell content unexpectedly.
```

Guard script:

```bash
bash scripts/security/assert_docs_shell_safety.sh
```

The guard rejects docs that contain:

```text
unquoted heredoc examples using EOF-style delimiters
directly executable content_releases whole-tree delete examples
directly executable source_pack or previous_pack bulk delete examples
```

It is wired into:

```text
scripts/security/assert_artifact_clean.sh
```

Safe writing rule:

```text
When writing Markdown or ledger content containing code fences, variables, backticks, or shell examples, use quoted heredoc delimiters or an editor.
Do not write dangerous operational examples through unquoted shell heredocs.
```

## Current main baseline

After #1455 merged and local main synced to origin/main:

```text
main == origin/main
worktree clean
CI for #1455 passed: hygiene, verify-mbti-legacy, verify-mbti-v2
no runtime mutation performed during cleanup
```

A post-merge audit baseline was captured at:

```text
/Users/rainie/fap-api-storage-release-roots-audit-baseline-20260517.json
```

The default MySQL connection was unavailable locally:

```text
root@localhost access denied
```

The successful baseline used the existing local SQLite database:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/Users/rainie/Desktop/GitHub/fap-api/backend/database/database.sqlite php artisan storage:release-roots:audit --format=json > /Users/rainie/fap-api-storage-release-roots-audit-baseline-20260517.json
```

Baseline summary:

```text
root_exists: true
root_empty: false
root_count: 84
bytes: 117264540
file_count: 1740

by_kind:
source_pack: 36
previous_pack: 12
current_pack: 12
unknown: 24

by_classification:
unreferenced_source_pack_review_required: 36
unreferenced_previous_pack_review_required: 12
unreferenced_current_pack_low_risk_candidate: 12
unknown_shape_review_required: 24

dangling_ref_count: 0
```

Safety envelope:

```text
cleanup_executed: false
plan_file_written: false
db_mutated: false
prune_invoked: false
delete_move_quarantine_options_available: false
```

DB reference scan summary:

```text
tables_scanned: 197
columns_scanned: 1480
content_release_related_refs: 0
generic_content_releases_refs: 0
dangling_refs: []
```

The `unknown_shape_review_required` entries in this baseline were empty backup parent directories:

```text
count: 24
bytes: 0
files: 0
```

They should be investigated before any future policy change, but they were not cleaned.

## Standard future scan flow

When storage grows again, use this order:

```text
1. Run storage:release-roots:audit with JSON output.
2. Verify the safety envelope.
3. Check dangling_ref_repair_required first.
4. Check unknown_shape_review_required next.
5. Review strong_keep roots.
6. Treat source_pack and previous_pack as review-only.
7. Discuss current_pack candidates only after audit review.
8. Do not run cleanup as the first response.
```

Recommended scan command:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan storage:release-roots:audit --format=json
```

If default MySQL is unavailable on a local machine, use an existing local SQLite database only for local baseline analysis:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/Users/rainie/Desktop/GitHub/fap-api/backend/database/database.sqlite php artisan storage:release-roots:audit --format=json
```

Inspect JSON safely:

```bash
python3 -m json.tool /Users/rainie/fap-api-storage-release-roots-audit-baseline-20260517.json | head -120
```

## What not to do

Do not start with cleanup.

Do not run:

```text
storage:prune with execute mode
manual recursive deletion of content_releases
manual movement of source_pack or previous_pack
manual deletion of source_pack or previous_pack
new cleanup executor commands
new quarantine executor commands
new delete executor commands
```

Do not treat these labels as safe-delete approvals:

```text
unreferenced_source_pack_review_required
unreferenced_previous_pack_review_required
unknown_shape_review_required
dangling_ref_repair_required
```

Do not make production/staging retention shorter as a side effect of local development cleanup.

## Safe local development posture

Recommended local posture:

```text
VS Code excludes runtime storage paths.
Local retention may be shorter than production.
content_releases remains runtime storage, not source code.
source_pack and previous_pack are not manually cleaned.
audit output is captured before any cleanup discussion.
```

Suggested local retention values:

```dotenv
STORAGE_RETENTION_RELEASES_KEEP_LAST=20
STORAGE_RETENTION_RELEASES_DAYS=14
```

More aggressive personal-only values:

```dotenv
STORAGE_RETENTION_RELEASES_KEEP_LAST=10
STORAGE_RETENTION_RELEASES_DAYS=7
```

Do not apply these local values blindly to production or staging.

## Future engineering guidance

The next phase should stay read-only until the audit command has been observed in real development flows.

Do not immediately build action-oriented commands such as:

```text
storage:release-roots:delete
storage:release-roots:clean
storage:release-roots:quarantine
```

If a future planning command is needed, it should remain read-only:

```text
no file writes
no plan file writes
no DB writes
no deletion
no movement
no quarantine creation
no prune invocation
no directly executable delete commands in output
```

A future read-only planner may map audit classifications to recommendations:

```text
strong_keep -> keep
dangling_ref_repair_required -> repair before cleanup
unknown_shape_review_required -> manual review
unreferenced_source_pack_review_required -> review only
unreferenced_previous_pack_review_required -> review only
unreferenced_current_pack_low_risk_candidate -> quarantine candidate discussion only
```

Even then, executor commands should remain a later and separate design discussion.

## Final closure statement

This governance chain has shifted the project from manual directory cleanup and ad hoc shell notes to a layered prevention model:

```text
release hygiene guard
local retention SOP
read-only release root audit
docs shell safety guard
CI enforcement
```

The current phase is closed. Future work should observe audit output first, avoid manual cleanup, and preserve source_pack and previous_pack until a manifest/catalog-aware review explicitly supports any next step.
