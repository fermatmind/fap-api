# Production seo_intel DB Migration Runbook

## A. Purpose

This is a production activation runbook only.

This PR does not create a production database, create database users, run production migrations, edit environment values, deploy, enable collectors, enable schedulers, connect external search APIs, deploy Metabase, or read production logs.

It does not create database users.

It does not run production migrations.

## B. Current Readiness Status

The SEO Intelligence MVP foundation is complete. Production activation remains blocked until a production `seo_intel` database, database users, backup/restore plan, migration approval, and ownership checklist are confirmed.

Metabase, GSC, Baidu, IndexNow, domestic search adapters, crawler log access, collector writes, scheduler activation, and CMS issue summary exposure remain blocked until separate human-approved activation steps.

## C. Production DB Placement Policy

`seo_intel` must be logically isolated from the business database and operational CMS write tables.

Allowed placement options:

- A managed MySQL/RDS instance dedicated to analytics or reporting.
- A separate database/schema on an approved managed MySQL/RDS cluster.
- A private analytics database reachable only from approved backend/ops networks.

Forbidden placement options:

- Node2 local DB.
- Node2 local Laravel, php84, fap-mysql, or local queue runtime.
- Raw business database tables as the analytics surface.
- CMS write tables as the analytics surface.

`seo_intel` is an observation and aggregation store. It is not CMS source of truth, business truth, payment truth, or content publishing authority.

## D. Required Users

`seo_intel_writer`

- Runtime writer for approved `seo_intel` tables only.
- May insert/update approved aggregate and issue queue tables after write activation.
- Must not read or write business tables.
- Must not access raw payment, email, order, attempt, cookie, or raw IP detail.

`seo_intel_metabase_readonly`

- Metabase read-only user.
- SELECT only on approved sanitized aggregate tables or views.
- No write, DDL, migration, business DB, CMS write table, raw event, raw order, raw payment, raw email, raw crawler log, or Node2 local DB access.

`migration_operator`

- Human-controlled migration-time identity.
- DDL permission only for approved `seo_intel` migrations during an approved change window.
- Must not be used by collectors, scheduler jobs, Metabase, or runtime application traffic.

## E. Permission Policy

- Writer cannot read or write business tables.
- Writer cannot access Node2 local DB.
- Writer cannot access raw business, order, payment, email, cookie, or raw IP detail outside approved business systems.
- Metabase cannot write.
- Metabase cannot read raw business DB tables.
- Metabase cannot read CMS write tables.
- Metabase cannot read Node2 local DB.
- Metabase cannot access raw email, raw order numbers, raw attempt IDs, payment IDs, provider event IDs, cookies, raw IPs, raw user agents, raw payloads, or payment payloads.
- `migration_operator` is not used for collector runtime.
- No `seo_intel` user may become content publishing authority.

## F. Migration Preflight Checklist

All items must be confirmed before any production migration:

- Production `seo_intel` database exists.
- `seo_intel_writer` exists with least-privilege runtime permissions.
- `seo_intel_metabase_readonly` exists with SELECT-only permissions.
- `migration_operator` is human-controlled and approved for the change window.
- Backup is confirmed.
- Restore procedure is known and assigned.
- Restore test or restore rehearsal evidence is available before write enablement.
- Maintenance/change window is approved.
- Current production code SHA is confirmed.
- Migration list is reviewed.
- Migration target is confirmed as `seo_intel`, not business DB and not Node2 local DB.
- PII guard is reviewed.
- Environment keys are prepared but not printed.
- `--pretend` migration output is reviewed.
- Rollback/forward-fix owner is assigned.
- Required GitHub checks for the release SHA are green.
- No deploy or production incident is in progress.

## G. Migration Command Templates

These are templates only. Do not paste secrets into commands, shell history, tickets, logs, PR bodies, or chat.

Production `seo_intel` migration commands must always include the dedicated migration path. Never run
`php artisan migrate --database=seo_intel` without `--path=database/migrations/seo_intel`; the default Laravel
migration directory contains normal app and CMS migrations that must not run against the `seo_intel` schema.

```bash
php artisan migrate --database=seo_intel --path=database/migrations/seo_intel --pretend --no-ansi --force
```

```bash
php artisan migrate --database=seo_intel --path=database/migrations/seo_intel --no-ansi --force
```

```bash
php artisan migrate:status --database=seo_intel --path=database/migrations/seo_intel --no-ansi
```

Use the approved production execution channel and masked environment configuration. Do not run these commands from this PR.
The migration target is `seo_intel` only; default business migrations must not run against `seo_intel`.
Default business migrations must not run against seo_intel.

## H. Backup / Restore / Rollback

`seo_intel` migrations are forward-only. Rollback is either:

- restore from a verified backup, or
- apply a human-reviewed forward-fix migration.

Restore must be tested before collector write enablement. If migration fails, keep or set:

- `SEO_INTEL_WRITE_ENABLED=false`
- `SEO_INTEL_COLLECTORS_ENABLED=false`
- scheduler disabled
- Metabase disconnected or read-only connection blocked

Failed migrations must not be repaired with ad hoc production edits. Use a reviewed forward-fix PR or restore plan.

## I. Post-Migration Validation

After approved migration execution:

- Confirm expected `seo_intel` tables exist.
- Confirm forbidden PII columns are absent.
- Confirm row counts are zero or expected for newly migrated tables.
- Confirm `seo_intel_writer` can write only approved `seo_intel` tables.
- Confirm `seo_intel_writer` cannot read/write business tables.
- Confirm `seo_intel_metabase_readonly` can SELECT only approved sanitized tables/views.
- Confirm `seo_intel_metabase_readonly` cannot INSERT, UPDATE, DELETE, ALTER, DROP, or CREATE.
- Confirm Metabase cannot write.
- Confirm Metabase cannot read business DB, CMS write tables, Node2 local DB, raw orders, raw payments, raw email, raw events, or raw crawler logs.
- Run collector dry-run/no-write smoke.
- Confirm `php artisan route:list --no-ansi` is unaffected.
- Confirm no production scheduler is enabled.
- Confirm no collector writes are enabled until a later approved step.

## J. No-Go Conditions

Stop before production migration if any condition is true:

- Production DB is not confirmed.
- Required DB users are not confirmed.
- Backup is missing.
- Restore plan is missing.
- Restore owner is missing.
- Node2 local DB is selected.
- Business DB raw tables are exposed to Metabase.
- CMS write tables are exposed to Metabase.
- PII columns are found.
- Env values would be printed or copied into logs.
- Production deploy is ongoing.
- Incident response is active.
- Required checks are red.
- Migration target is ambiguous.
- Rollback/forward-fix owner is not assigned.

## K. Next Task

Next task: `SEO-DASH-PROD-01B-STAGE1-RETRY` for a human-approved production migration Stage 1 retry using the isolated `seo_intel` migration path.

If production DB and migrations are complete under human approval, the next activation step may proceed to `SEO-DASH-PROD-02` collector dry-run smoke.
