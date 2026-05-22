# Issue Severity, Dedupe, Mute, and SLA Contract

## Purpose

SEO-OBS-GOV-03 defines how `seo_issue_queue` should evolve from a safe issue
observation table into an operator governance surface.

This PR is contract-only. It does not add a real migration, mutate issues,
auto-fix issues, auto-publish content, mutate Search Channel Queue, submit URLs,
enable scheduler jobs, edit production environment, or modify `fap-web`.

## Severity Levels

Future operational severity levels:

- P0 / critical
- P1 / high
- P2 / medium
- P3 / info

Backward-compatible mapping from current labels:

- critical -> P0
- high -> P1
- warning -> P2
- info -> P3

## P0 Rules

P0 issues require explicit operator review and must never be silently muted.

Required P0 examples:

- claim-unsafe public/indexable page
- private-flow leak into public/search surface
- Search Channel submitted non-canonical/private URL
- public indexed page accidentally noindex
- canonical points to wrong URL on core public surface

## Required Future Fields

- dedupe_key
- muted_until
- mute_reason
- muted_by
- owner_team
- sla_due_at
- sla_policy
- reopen_rule
- reopened_at
- last_seen_at
- occurrence_count
- closed_reason

## Dedupe Rule

`dedupe_key` must be deterministic. It should be generated from safe issue
dimensions such as issue type, source system, source engine, canonical URL hash,
entity key, locale, page entity type, and normalized issue target. It must not
include raw payloads, raw crawler-log fields, raw request data, query strings,
email, tokens, cookies, order identifiers, payment identifiers, or attempt
identifiers.

Plain-language rule: dedupe_key must be deterministic.

`issue_uid` may remain a write idempotency key, but operator dedupe must be
explicit through `dedupe_key`.

## Mute Rule

Mute must not delete issue history. Muted issues must remain auditable.
`muted_until`, `mute_reason`, and `muted_by` are required for future mute
behavior. P0 must never be silently muted.

Muted issues may remain visible in `/ops/seo` behind filters and counters.

## SLA Rule

`sla_due_at` and `sla_policy` must be deterministic from severity, page family,
claim risk, source system, and ownership. SLA must not trigger auto-fix,
auto-publish, auto-submit, or Search Channel retry behavior.

## Owner Rule

`owner_team` should be a role/team string, not a private personal identity. The
first expected values are:

- seo_ops
- cms_ops
- engineering
- content_review
- digital_pr
- unknown

## Reopen Rule

`reopen_rule` must be explicit. Reopen should happen only when a previously
closed or muted issue reappears with a matching dedupe key and fresh evidence,
or when a P0 class issue is observed again after closure.

Reopen behavior must preserve prior history using `reopened_at`,
`last_seen_at`, and `occurrence_count`.

## Forbidden Behavior

Issue severity must not trigger:

- auto-fix
- CMS publish
- CMS unpublish
- Search Channel submission
- Search Channel retry
- scheduler activation
- collector writes
- production migration
- raw crawler-log read
- business DB access
- Metabase exposure

## Final Decision

`issue_severity_governance_contract_ready_without_migration`

Next task: `SEO-OBS-GOV-04`
