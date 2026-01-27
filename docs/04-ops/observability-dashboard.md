# PR9 Observability Dashboards

This document describes the PR9 observability dashboards (Grafana + Metabase), SQL views, and acceptance steps.

## Dashboards

Grafana dashboards (auto provisioned):
- FAP Ops Overview
- FAP Healthz Deps
- FAP Deploy Timeline

Metabase dashboards: see `tools/metabase/dashboards/README.md` for suggested layout.

## Alerts

- Healthz deps red rate: based on `v_healthz_deps_daily`
- HTTP 5xx spike: placeholder (requires future metrics view)
- Queue backlog: placeholder (uses queue red rate as proxy)

## Local verification

From repo root:

```
cd backend
php artisan migrate
bash scripts/pr9_verify_views.sh

cd ..
cp tools/compose/.env.example tools/compose/.env

docker compose -f tools/compose/observability.yml --env-file tools/compose/.env up -d
```

## Access

- Grafana: http://localhost:3000 (default user: admin / password from tools/compose/.env)
- Metabase: http://localhost:3001 (first-time setup required)

## Suggested cron (server)

```
*/5 * * * * cd /var/www/fap-api/current/backend && php artisan ops:healthz-snapshot >/dev/null 2>&1
```
