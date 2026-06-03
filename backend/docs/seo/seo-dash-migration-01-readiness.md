# SEO-DASH-MIGRATION-01 seo_intel Migration Readiness

## Purpose

SEO-DASH-MIGRATION-01 locks the production `seo_intel` migration readiness
contract after the read-only API route family exists.

This PR is a readiness contract only. It does not create a production database,
edit production environment values, run production migrations, deploy, enable
collector writes, enable scheduler jobs, connect GSC/Baidu/GA4, mutate CMS
records, submit URLs, or modify `fap-web`.

Hard boundary: this PR does not run production migrations.

## Dependency Boundary

Required merged dependencies:

- `SEO-DASH-00-RECONCILE`: schema, PII/consent, source-of-truth, and read-only
  API boundary.
- `SEO-DASH-API-01`: private read-only `/api/v0.5/ops/seo-intel/*` API route
  family and `admin.seo_intel.read` permission contract.
- Existing migration isolation: `seo_intel` migrations are under
  `database/migrations/seo_intel` and use `protected $connection = 'seo_intel';`.

## Required Migration Commands

Never run a bare `php artisan migrate --database=seo_intel` command. The
`seo_intel` migration path must always be explicit:

```bash
php artisan migrate --database=seo_intel --path=database/migrations/seo_intel --pretend --no-ansi --force
php artisan migrate:status --database=seo_intel --path=database/migrations/seo_intel --no-ansi
php artisan migrate --database=seo_intel --path=database/migrations/seo_intel --no-ansi --force
```

The actual migration command is permitted only after separate production
approval. This PR does not grant that approval.

## Human Approval Gate

Production migration requires an exact approval phrase with a resolved SHA and
a reviewed pretend output:

```text
I explicitly approve production seo_intel migration for SHA <resolved_sha> using database/migrations/seo_intel after reviewed pretend output, backup confirmation, restore procedure confirmation, and migration operator confirmation.
```

No placeholder SHA, missing backup/restore evidence, ambiguous DB target, or
ongoing incident may pass this gate.

## Preflight Checklist

Required before any production migration:

- production `seo_intel` DB location is confirmed and is not Node2 local DB
- `seo_intel` database name is confirmed without printing secrets
- `seo_intel_writer` is confirmed for future write-enabled collectors only
- `seo_intel_metabase_readonly` is confirmed for sanitized read-only access
- `migration_operator` is confirmed for temporary DDL only
- backup is confirmed
- restore procedure and restore owner are confirmed
- current code SHA is confirmed
- required GitHub checks are green
- migration list and pretend output are reviewed
- PII guard is reviewed
- no production deploy or incident response is in progress

## Current Migration Inventory

The current isolated `seo_intel` migration set contains URL Truth, entity,
traffic rule, funnel, attribution, revenue, cluster, consent, GSC, Baidu,
IndexNow, domestic search, crawler, issue queue, search channel queue, and
crawler aggregate tables.

Migration readiness does not mean collectors are enabled. These tables remain
observation/read-model infrastructure until a later collector/live-readiness PR
is separately authorized.

## Post-Migration Validation

After an approved production migration, validate:

- migration status shows only `database/migrations/seo_intel` migrations
- expected `seo_intel` tables exist
- forbidden raw PII columns are absent
- row counts are zero or expected for the activation stage
- read-only API route list is unchanged
- collector writes remain disabled
- scheduler jobs remain disabled
- `fap-web` remains a dashboard shell and not an authority system

## No-Go Conditions

Stop if any condition is true:

- production DB target is ambiguous
- Node2 local DB is selected
- backup or restore procedure is missing
- migration operator is missing
- required checks are red
- pretend output is not reviewed
- secret values would be printed
- raw PII columns would be introduced
- default business migrations would run against `seo_intel`
- collector writes or scheduler jobs would be enabled
- CMS mutation, publish, URL submission, or deployment is bundled into the same
  operation

## Next Task

`SEO-DASH-COLLECTOR-01` may proceed only after a separately approved production
migration readiness/deploy flow. Collector writes remain disabled in this PR.
