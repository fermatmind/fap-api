# SEO-DASH-01B seo_intel Collector Skeleton

## Purpose

This document records the SEO-DASH-01B Search Intelligence collector skeleton. It adds the service and command shape needed for later collectors, but it does not enable production collection.

## Non-Activation Boundary

- No production collector is enabled.
- No scheduler job is enabled.
- No queue worker is created.
- No external API is connected.
- No GSC, Baidu, IndexNow, Metabase, or crawler-log integration is implemented.
- No database writes are committed by default.
- No collector reads Node2 local Laravel, Node2 local DB, or Node2 local queue.
- No fap-web runtime, sitemap, llms, payment, order, report, email, recommendation, or scoring behavior is changed.

## Runtime Shape

The collector boundary consists of:

- `SeoIntelCollector`: interface for future collectors.
- `SeoIntelCollectorResult`: safe result object for command and tests.
- `SeoIntelCollectorManager`: resolver and guard layer.
- `NoopSeoIntelCollector`: dry-run-only skeleton collector.
- `seo-intel:collect`: Artisan command for explicit manual invocation.

The only collector implemented in this PR is `noop`. It performs no production data reads, no DB writes, and no external calls.

## Default Config

The `seo_intel` config remains disabled by default:

- `enabled = false`
- `write_enabled = false`
- `collectors_enabled = false`
- `dry_run_default = true`
- `allow_external_api_calls = false`
- `allowed_collectors = [noop]`
- `default_collector = noop`

`noop` may be invoked as a dry-run command so that the command contract can be tested without enabling collection.

## Command Boundary

Safe dry-run command:

```bash
php artisan seo-intel:collect --collector=noop --dry-run --json
```

The command output is safe JSON and must not include email, raw order identifiers, raw attempt identifiers, payment identifiers, cookies, tokens, or secrets.

Unknown collectors are rejected by the manager. Non-dry-run collector execution remains blocked while collectors are disabled.

## Deferred Collectors

The following are explicitly deferred:

- URL Truth Inventory collector
- drift collector
- crawler log collector
- funnel attribution collector
- revenue daily builder
- GSC collector
- Baidu collector
- IndexNow collector
- Metabase dashboard deployment
- CMS issue queue producer

## Source Authority

Future collectors must use the accepted backend authority source from BACKEND-RUNTIME-02D. Node2 is an API edge gateway only; Node2 local Laravel, local DB, php84, fap-mysql, and local queue are not Search Intelligence data sources.

## Next Task

The next implementation task is `SEO-DASH-02A`: URL Truth Inventory after the disabled collector skeleton is accepted.
