# PR12 Recon — AI insights: evidence + budget breaker (Redis)

Date: 2026-01-28
Branch: chore/pr12-ai-insights-budget

## Scope
- Goal: add AI insight generation (mock provider) with evidence trace, feedback loop, and Redis-backed budget breaker.
- Constraints: keep changes minimal; follow change order; migrations must be idempotent; keep CI chain green.

## Recon Commands (executed)
- `ls -lah`
- `rg -n "insight|insights|ai_insight|Budget|redis|queue|metabase|v_ai_" backend/app backend/routes backend/database docs tools -S`
- `ls -lah backend/database/migrations | tail -n 120`
- `php -v`
- `php artisan -V`
- `php artisan route:list > /tmp/routes_pr12.txt && rg -n "insight|insights|ai|psychometrics|admin" /tmp/routes_pr12.txt`
- `php -m | rg -n "^redis$"`

## Existing Entry Points / Related Files
- Routes: `backend/routes/api.php` has `/api/v0.2/*` groups with Admin, Auth, Attempts, Reports, Payments, etc. No insights routes yet.
- Middleware: `backend/app/Http/Middleware/FmTokenAuth.php` resolves `fm_user_id`/`anon_id` and injects into request attributes.
- Queue: `backend/config/queue.php` defines `sync`, `database`, `redis`, etc. No dedicated insights queue name yet.
- Redis: PHP `redis` extension is installed; Healthz controller checks redis/queue (`backend/app/Http/Controllers/HealthzController.php`).
- Content/Report: `backend/app/Services/Report/HighlightBuilder.php` includes “insight” placeholders but no AI insight pipeline.
- Metabase: no existing `v_ai_*` views; Metabase SQL templates live under `tools/metabase/*`.

## Existing DB Tables (by migrations)
- No `ai_*` tables currently present in `backend/database/migrations`.
- Latest migrations are for admin, psychometrics snapshot, and quality tables (2026-01-28).

## Routes Snapshot
- Current `/api/v0.2` includes auth, attempts, reports, payments, admin endpoints.
- No `/api/v0.2/insights` routes in `php artisan route:list` output.

## Risks + Avoidance
- Redis unavailable → budget ledger cannot increment/check.
  - Mitigate with explicit `fail_open_when_redis_down` config; default fail-closed if breaker enabled.
- Queue not running → insights remain queued.
  - Mitigate by using database queue by default; provide verify script with polling + timeout.
- Cost runaway → budget breaker required at enqueue (estimated) and on completion (actual tokens).
- Prompt drift / regressions → enforce `prompt_version` and document versioning workflow.
- Evidence not traceable → build evidence hash + structured pointers; avoid PII.

## Required Additions/Changes (PR12)
- Routes: `/api/v0.2/insights/*` endpoints under v0.2 group with budget middleware.
- Config: new `backend/config/ai.php` + optional queue config note.
- Migrations: `ai_insights` + `ai_insight_feedback` tables (idempotent).
- Services: `BudgetLedger`, `InsightGenerator`, `EvidenceBuilder`.
- Middleware: `CheckAiBudget` (Redis-backed checks).
- Job: `GenerateInsightJob` (async generation + ledger increment).
- Controller: `API/V0_2/InsightsController` (generate/get/feedback).
- Metabase views: `v_ai_*` SQL templates in `tools/metabase/views/`.
- Script: `backend/scripts/pr12_verify_ai_insights.sh` with artifacts in `backend/artifacts/pr12/`.
- Docs: insight spec, prompt versioning, safety policy, cost runbook, verify notes.

## Notes
- No code changes made beyond this recon doc in this loop.
- Next loop must follow change order: `backend/routes/api.php` → migrations → `CheckAiBudget` → controller/service → scripts.
