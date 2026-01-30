# PR23 Verify

## Commands
```bash
export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

bash backend/scripts/pr23_accept.sh
bash backend/scripts/ci_verify_mbti.sh
```

## Expected outputs
- Artifacts in `backend/artifacts/pr23/`
  - `curl_boot_a.json`, `curl_boot_b.json`, `curl_boot_a_repeat.json`, `curl_event.json`
  - `anon_pair.json`, `db_assertions.json`, `experiments_agg.json`
  - `server.log`, `verify.log`, `summary.txt`
- `/api/v0.3/boot` returns stable `experiments.PR23_STICKY_BUCKET`
- `events.experiments_json` contains `PR23_STICKY_BUCKET`
- `experiment_assignments` persists sticky variant per anon_id
