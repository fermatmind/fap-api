# PR25 Verify

## Commands
```bash
export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

bash backend/scripts/pr25_accept.sh
bash backend/scripts/ci_verify_mbti.sh
```

## Expected outputs
- Artifacts in `backend/artifacts/pr25/`
  - `verify.log`, `server.log`, `summary.txt`
  - `progress.json`, `summary.json`
  - `attempt_start_*.json`, `attempt_submit_*.json`
- `/api/v0.4/orgs/{org_id}/assessments/*` available for owner/admin only
- progress shows total=10 completed=3 pending=7 after 3 submits
- summary includes `completion_rate`, `window`, `score_distribution`, `dimension_means`
- credits insufficient returns 402 + `CREDITS_INSUFFICIENT`
