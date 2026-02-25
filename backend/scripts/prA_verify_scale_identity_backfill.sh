#!/usr/bin/env bash
set -euo pipefail

ROOT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ART_DIR="$ROOT_DIR/artifacts/prA_backfill"
mkdir -p "$ART_DIR"

cd "$ROOT_DIR"

php artisan migrate --force >/dev/null
php artisan fap:scales:seed-default >/dev/null

commands=(
  "ops:backfill-attempts-scale-identity"
  "ops:backfill-results-scale-identity"
  "ops:backfill-events-scale-identity"
  "ops:backfill-shares-scale-identity"
  "ops:backfill-report-snapshots-scale-identity"
  "ops:backfill-orders-scale-identity"
  "ops:backfill-payment-events-scale-identity"
  "ops:backfill-attempt-answer-sets-scale-identity"
  "ops:backfill-attempt-answer-rows-scale-identity"
  "ops:backfill-assessments-scale-identity"
)

dry_report="$ART_DIR/dry_run.txt"
exec_report="$ART_DIR/execute_run.txt"
idempotent_report="$ART_DIR/idempotent_run.txt"

: > "$dry_report"
: > "$exec_report"
: > "$idempotent_report"

for command in "${commands[@]}"; do
  output="$(php artisan "$command" --chunk=200 --dry-run)"
  printf '>>> %s --dry-run\n%s\n\n' "$command" "$output" | tee -a "$dry_report" >/dev/null
  if ! grep -Eiq 'scanned=[0-9]+.*updated=[0-9]+.*skipped_unknown=[0-9]+.*dry_run=1' <<<"$output"; then
    echo "[FAIL] dry-run summary format invalid: $command" >&2
    exit 1
  fi
done

for command in "${commands[@]}"; do
  output="$(php artisan "$command" --chunk=200)"
  printf '>>> %s\n%s\n\n' "$command" "$output" | tee -a "$exec_report" >/dev/null
  if ! grep -Eiq 'scanned=[0-9]+.*updated=[0-9]+.*skipped_unknown=[0-9]+.*dry_run=0' <<<"$output"; then
    echo "[FAIL] execute summary format invalid: $command" >&2
    exit 1
  fi
done

for command in "${commands[@]}"; do
  output="$(php artisan "$command" --chunk=200)"
  printf '>>> %s \(second pass\)\n%s\n\n' "$command" "$output" | tee -a "$idempotent_report" >/dev/null
  if ! grep -Eiq 'scanned=[0-9]+.*updated=0.*skipped_unknown=[0-9]+.*dry_run=0' <<<"$output"; then
    echo "[FAIL] idempotence check failed: $command" >&2
    exit 1
  fi
done

cat > "$ART_DIR/summary.txt" <<'TXT'
PR-A scale identity backfill verification summary

Checks:
- migrate --force: OK
- fap:scales:seed-default: OK
- 10 backfill commands dry-run summaries: OK
- 10 backfill commands execute summaries: OK
- second execute pass updated=0 (idempotence): OK

Artifacts:
- backend/artifacts/prA_backfill/dry_run.txt
- backend/artifacts/prA_backfill/execute_run.txt
- backend/artifacts/prA_backfill/idempotent_run.txt
- backend/artifacts/prA_backfill/summary.txt
TXT

echo "[OK] scale identity backfill verification complete."

