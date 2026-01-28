# PR12 Verify — AI Insights

## Commands
```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
composer install
composer audit
php artisan migrate
PORT=18020 bash scripts/pr12_verify_ai_insights.sh

cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/ci_verify_mbti.sh
```

## Expected Outputs
- `backend/artifacts/pr12/summary.txt` exists
- `summary.txt` includes:
  - `insight_id=...`
  - `tokens_in=...` and `tokens_out=...`
  - `cost_usd=...`
  - `breaker_test=AI_BUDGET_EXCEEDED`
  - `port=...`

## Notes
- If port 18020 is busy, the script will auto-pick from 18020–18039 and log a kill hint.
- Budget breaker test uses `AI_DAILY_TOKENS=1` during the second server run.
