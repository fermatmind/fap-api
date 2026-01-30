#!/usr/bin/env bash
set -euo pipefail

export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

export DB_CONNECTION=sqlite
export DB_DATABASE=/tmp/pr21.sqlite
rm -f /tmp/pr21.sqlite

export SERVE_PORT=1821
lsof -ti tcp:1821 | xargs -r kill || true

ART_DIR="backend/artifacts/pr21"
mkdir -p "$ART_DIR"

(cd backend && composer install --no-interaction --no-progress)
(cd backend && php artisan migrate --force)
(cd backend && php artisan fap:scales:seed-default)
(cd backend && php artisan fap:scales:sync-slugs)
(cd backend && php artisan db:seed --class=Pr21AnswerDemoSeeder)
(cd backend && php artisan test --filter=V0_3)
(cd backend && bash scripts/pr21_verify_answer_storage.sh)

PYTHON_BIN="python"
if ! command -v "$PYTHON_BIN" >/dev/null 2>&1; then
  PYTHON_BIN="python3"
fi
if ! command -v "$PYTHON_BIN" >/dev/null 2>&1; then
  echo "python not found" >&2
  exit 1
fi

"$PYTHON_BIN" - <<'PY'
import json
import os
from datetime import datetime

art_dir = 'backend/artifacts/pr21'
start_path = os.path.join(art_dir, 'curl_start.json')
progress_path = os.path.join(art_dir, 'curl_progress_get.json')
db_path = os.path.join(art_dir, 'db_assertions.json')

start = {}
progress = {}
db = {}

for path, target in [(start_path, start), (progress_path, progress), (db_path, db)]:
    if os.path.isfile(path):
        with open(path, 'r', encoding='utf-8') as f:
            try:
                target.update(json.load(f))
            except Exception:
                pass

summary = []
summary.append('PR21 accept summary')
summary.append('time: ' + datetime.utcnow().strftime('%Y-%m-%d %H:%M:%S UTC'))
summary.append('passed: migrate, seed, tests(V0_3), pr21_verify_answer_storage')
summary.append('api_smoke: attempt_id=' + str(start.get('attempt_id','')))
summary.append('api_smoke: answered_count=' + str(progress.get('answered_count','')))
summary.append('db: answer_sets=' + str(db.get('answer_sets','')) + ', answer_rows=' + str(db.get('answer_rows','')))
summary.append('db: archive_audits=' + str(db.get('archive_audits','')))
summary.append('tables: attempt_drafts, attempt_answer_sets, attempt_answer_rows, archive_audits, attempts.resume_expires_at')

with open(os.path.join(art_dir, 'summary.txt'), 'w', encoding='utf-8') as f:
    f.write('\n'.join(summary) + '\n')
PY

bash backend/scripts/sanitize_artifacts.sh 21
