# Metabase (PR9 Observability)

Metabase is used for business/ops exploration on top of PR9 SQL views (v_*).

## Quick start (with compose)

1) Create env file:

```
cp tools/compose/.env.example tools/compose/.env
```

2) Start services:

```
docker compose -f tools/compose/observability.yml --env-file tools/compose/.env up -d
```

Metabase will be available at http://localhost:3001.

## Connect to MySQL

Use the same MySQL credentials as Grafana:
- host: GF_MYSQL_HOST
- port: GF_MYSQL_PORT
- database: GF_MYSQL_DB
- user: GF_MYSQL_USER
- password: GF_MYSQL_PASSWORD

## Questions

This repo provides SQL questions in `tools/metabase/questions/*.sql`.

In Metabase:
- New -> SQL query -> paste SQL
- Save to collection "PR9 Observability"

## Dashboards

See `tools/metabase/dashboards/README.md` for a suggested layout.
