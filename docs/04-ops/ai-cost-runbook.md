# AI Cost Runbook

## Symptoms
- AI insights blocked with `AI_BUDGET_EXCEEDED`
- Insights stuck in `queued` or `running`
- Sudden spike in `cost_usd` or `tokens` usage

## Quick Checks
1) Redis availability
   - `php artisan tinker --execute="Redis::connection()->ping();"`
2) Budget ledger keys
   - `php artisan tinker --execute="Redis::keys('ai:budget:*');"`
3) DB usage
   - `php artisan tinker --execute="DB::table('ai_insights')->orderByDesc('created_at')->limit(5)->get();"`
4) Queue health
   - `php artisan queue:work --once --queue=insights`

## Breaker Reset / Recovery
- Temporary relief (raise limits):
  - Set env: `AI_DAILY_TOKENS`, `AI_MONTHLY_TOKENS`, `AI_DAILY_USD`, `AI_MONTHLY_USD`
- Clear ledger keys (last resort):
  - `php artisan tinker --execute="Redis::del(Redis::keys('ai:budget:day:*'));"`
  - `php artisan tinker --execute="Redis::del(Redis::keys('ai:budget:month:*'));"`
- Disable breaker (short-term only):
  - `AI_BREAKER_ENABLED=false`

## Common Failures
- Redis down → `AI_BUDGET_LEDGER_UNAVAILABLE`
- Queue worker not running → `queued` insights never complete
- Provider timeout → `AI_INSIGHT_FAILED`

## Monitoring
- `tools/metabase/views/v_ai_cost_daily.sql`
- `tools/metabase/views/v_ai_cost_alert.sql`
- `tools/metabase/views/v_ai_failure_reasons.sql`
- `tools/metabase/views/v_ai_quality_feedback.sql`
