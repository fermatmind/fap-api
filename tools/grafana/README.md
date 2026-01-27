# Grafana (PR9 Observability)

This folder contains provisioning, dashboards, and alert rules for PR9.

## Quick start (with compose)

1) Create env file:

```
cp tools/compose/.env.example tools/compose/.env
```

2) Start services:

```
docker compose -f tools/compose/observability.yml --env-file tools/compose/.env up -d
```

Grafana will be available at http://localhost:3000 (default user: admin).

## Provisioning

- Datasource: `tools/grafana/provisioning/datasources/mysql.yml`
- Dashboards: `tools/grafana/provisioning/dashboards/dashboards.yml`
- Dashboards JSON: `tools/grafana/dashboards/*.json`

The MySQL datasource uses env vars:

- GF_MYSQL_HOST
- GF_MYSQL_PORT
- GF_MYSQL_DB
- GF_MYSQL_USER
- GF_MYSQL_PASSWORD

## Alerts

Alert rule examples are in `tools/grafana/alerts/*.yml`.

Import:
- Grafana UI -> Alerting -> Alert rules -> Import

Notes:
- `healthz-deps-red.yml` is based on `v_healthz_deps_daily`.
- `http-5xx-spike.yml` and `queue-backlog.yml` are placeholders; update when metrics exist.
