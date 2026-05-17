# Storage Local Retention SOP

## Purpose

`backend/storage/app/private/content_releases` is Laravel runtime storage. It is not source code and must not be treated as a source, release, or packaging directory.

This SOP is for local development hygiene only. It does not change production or staging retention policy.

## VS Code local exclusions

For local workspaces that become slow because VS Code scans runtime storage, add these patterns to VS Code User Settings. Use project `.vscode/settings.json` only if the team agrees to commit editor policy.

```json
{
  "files.exclude": {
    "**/backend/storage/app/private/content_releases": true,
    "**/backend/storage/app/private/artifacts": true,
    "**/backend/storage/framework": true,
    "**/backend/storage/logs": true
  },
  "search.exclude": {
    "**/backend/storage/app/private/content_releases": true,
    "**/backend/storage/app/private/artifacts": true,
    "**/backend/storage/framework": true,
    "**/backend/storage/logs": true
  },
  "files.watcherExclude": {
    "**/backend/storage/app/private/content_releases/**": true,
    "**/backend/storage/app/private/artifacts/**": true,
    "**/backend/storage/framework/**": true,
    "**/backend/storage/logs/**": true
  }
}
```

## Local retention settings

For local development, use a shorter retention window when repeated content release work creates large runtime trees:

```dotenv
STORAGE_RETENTION_RELEASES_KEEP_LAST=20
STORAGE_RETENTION_RELEASES_DAYS=14
```

A more aggressive personal-only setting is:

```dotenv
STORAGE_RETENTION_RELEASES_KEEP_LAST=10
STORAGE_RETENTION_RELEASES_DAYS=7
```

Do not shorten production or staging retention casually. The default `STORAGE_RETENTION_RELEASES_DAYS=180` exists to preserve rollback, rehydrate, manifest, and audit safety windows.

## Prune command warning

`php artisan storage:prune --dry-run --scope=content_releases_retention` is not strictly no-write. It writes a prune plan under:

```text
backend/storage/app/private/prune_plans
```

Use it as a safe no-delete planning command, not as a read-only scan.

Do not run:

```bash
php artisan storage:prune --execute --scope=content_releases_retention
```

unless the target plan and rollback implications have been explicitly reviewed.

## current_pack quarantine cleanup SOP

Use this only for local development cleanup. Do not use it for production or staging hosts.

First inventory local `current_pack` directories:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api
python3 - <<'PY'
from pathlib import Path
repo = Path("/Users/rainie/Desktop/GitHub/fap-api")
root = repo / "backend/storage/app/private/content_releases/backups"
targets = sorted(root.glob("*/current_pack"))
total_files = 0
total_bytes = 0
for p in targets:
    for f in p.rglob("*"):
        if f.is_file():
            total_files += 1
            total_bytes += f.stat().st_size
print(f"current_pack_dirs={len(targets)}")
print(f"current_pack_files={total_files}")
print(f"current_pack_size_mib={total_bytes / 1024 / 1024:.1f}")
print()
print("first_20_targets:")
for p in targets[:20]:
    print(p)
PY
```

Then inspect local DB references before moving anything:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan tinker --execute='
$tables = ["content_pack_releases", "content_release_manifests", "content_release_exact_manifests", "content_release_exact_manifest_files"];
$columns = ["storage_path", "source_storage_path", "canonical_root", "root_path", "path"];
foreach ($tables as $table) {
    if (! Schema::hasTable($table)) {
        continue;
    }
    $query = DB::table($table);
    $hasColumn = false;
    foreach ($columns as $column) {
        if (Schema::hasColumn($table, $column)) {
            $hasColumn = true;
            $query->orWhere($column, "like", "%content_releases/backups/%/current_pack%");
        }
    }
    echo $table.": ".($hasColumn ? $query->count() : 0).PHP_EOL;
}
'
```

Only after inventory and DB reference review, move `backups/*/current_pack` outside the repo into quarantine:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api
APPLY=1 python3 - <<'PY'
from pathlib import Path
from datetime import datetime
import os
import shutil
import sys
repo = Path("/Users/rainie/Desktop/GitHub/fap-api")
root = repo / "backend/storage/app/private/content_releases/backups"
stamp = datetime.now().strftime("%Y%m%d-%H%M%S")
quarantine = Path.home() / f"fap-api-current-pack-quarantine-{stamp}"
targets = sorted(root.glob("*/current_pack"))
print(f"targets={len(targets)}")
print(f"quarantine={quarantine}")
if os.environ.get("APPLY") != "1":
    print("preview only; set APPLY=1 to move")
    sys.exit(0)
for p in targets:
    rel = p.relative_to(repo)
    dest = quarantine / rel
    dest.parent.mkdir(parents=True, exist_ok=True)
    shutil.move(str(p), str(dest))
print("done")
PY
```

After quarantine, validate local app health:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend && php artisan route:list --no-ansi
cd /Users/rainie/Desktop/GitHub/fap-api && bash backend/scripts/ci_verify_mbti.sh
```

Delete the quarantine directory only after local validation passes and the developer confirms the moved `current_pack` copies are no longer needed.

## Do not blindly delete source_pack or previous_pack

Do not blindly delete:

```text
backend/storage/app/private/content_releases/*/source_pack
backend/storage/app/private/content_releases/backups/*/previous_pack
```

These roots can participate in source evidence, exact manifests, rollback, rehydrate, and catalog/audit flows.

A later manifest/catalog-aware audit should classify release roots before deleting `source_pack` or `previous_pack`.

## Release root audit before cleanup

Before any future local cleanup of `source_pack`, `previous_pack`, or newly accumulated `current_pack` roots, run the read-only audit command first:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan storage:release-roots:audit --format=json
```

The audit command is classification-only. It must not delete files, move files, create quarantine directories, run `storage:prune`, write prune plans, or mutate the database.

Treat classifications conservatively:

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

`unreferenced_current_pack_low_risk_candidate` is still only a review candidate. `unreferenced_source_pack_review_required` and `unreferenced_previous_pack_review_required` are not safe-delete labels.

## Safe ledger and Markdown writing

When writing local ledgers, SOPs, or Markdown notes that contain code fences, variables, backticks, or shell command examples, use a quoted heredoc delimiter:

```bash
cat > /Users/rainie/fap-api-local-ledger.md <<'EOF'
Markdown text can safely include code fences, variables, backticks, and command examples here.
EOF
```

Do not use an unquoted heredoc with an EOF delimiter for that content. Unquoted heredocs allow shell expansion, so Markdown code fences and backticks can be interpreted by the shell instead of being written as text.

Do not include directly executable whole-tree deletion examples in operational ledgers or cleanup notes.

## Docs shell safety guard

The repository includes `scripts/security/assert_docs_shell_safety.sh` to catch repeatable documentation hazards before they enter release hygiene or artifact-clean checks.

The guard fails docs that contain:

```text
unquoted heredoc examples that use EOF as the delimiter
directly executable backend/storage/app/private/content_releases whole-tree delete examples
directly executable source_pack or previous_pack bulk delete examples
```

Quoted heredoc delimiters such as `<<'EOF'`, `<<"EOF"`, and `<<-'EOF'` remain allowed.

This guard is documentation safety only. It must not delete files, move files, run `storage:prune`, write prune plans, mutate the database, or change retention behavior.
