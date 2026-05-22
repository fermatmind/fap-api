# Observation Governance Architecture Contract

## Purpose

SEO-OBS-GOV-01 defines the architecture contract for SEO observation governance.
It connects CMS/backend publish signals, runtime verification, Search Channel
Queue states, crawler aggregate observations, claim-boundary checks, Issue Queue
lifecycle, and future `/ops/seo` display readiness.

This PR is documentation, generated JSON, and focused tests only. It does not add
migrations, runtime writers, scheduler jobs, production operations, collector
writes, Search Channel submissions, crawler-log readers, CMS mutations, or
Metabase exposure.

## Authority Model

CMS/backend remains the source of truth for publish state, public content,
metadata, canonical paths, indexability, locale links, and claim-boundary state.
`fap-web` deterministically renders public runtime from backend/CMS authority.
`seo_intel` observes and reports. It does not own public content truth.

Observation Queue is a future verification queue. It is not URL Truth, not
Search Channel Queue, not CMS authority, and not a crawler log reader.
In short: not URL Truth, not Search Channel Queue, and not CMS authority.

Search Channel Queue may submit only already approved URL Truth under its own
human-gated rules. Observation governance may observe Search Channel lifecycle
states, but it must not approve, retry, enqueue, or submit URLs.

Crawler Log observation may use sanitized aggregate counters only. It must not
read raw production crawler logs, store raw request data, create URL Truth, or
infer indexability/canonical truth.

Digital PR signals are observation-only. Referral, mention, or backlink
observations may help manual review, but they must not drive automated SEO
decisions.

## Allowed Observation Sources

- CMS/backend publish and metadata-change signals.
- Backend runtime verification results.
- URL Truth safe identifiers and canonical hashes.
- Search Channel Queue safe lifecycle states.
- Crawler aggregate observation counters.
- Issue Queue safe lifecycle states.
- Claim-boundary state from backend/CMS authority.
- Manual Digital PR observation records.
- `/ops/seo` read models using sanitized fields only.

## Forbidden Authority Sources

- frontend fallback
- static sitemap fallback
- static llms fallback
- crawler logs as URL Truth
- search engine responses as URL Truth
- local copies as authority
- Node2 local DB
- Tencent RDS business tables
- raw crawler logs
- raw request payloads
- private CMS data dumps
- Metabase as write path

## Event Source Model

CMS/backend events may create future observation rows when a public SEO-relevant
field changes. Runtime events may close or progress observation rows after safe
verification. Search Channel and crawler events may move a row into waiting or
review states. Issue lifecycle events may create or reopen review work.

Observation events must be idempotent, sanitized, and tied to safe identifiers
such as canonical URL hash, locale, page entity type, entity id or slug, and
future entity key.

## Required Event Types

- published
- unpublished
- metadata_changed
- canonical_changed
- robots_changed
- locale_link_changed
- claim_boundary_changed
- runtime_verified
- search_channel_enqueued
- search_channel_submitted
- crawler_signal_observed
- digital_pr_signal_observed
- issue_detected
- issue_muted
- issue_reopened

## Required Event States

- pending_runtime_check
- runtime_verified
- awaiting_search_engine_observation
- awaiting_crawler_observation
- needs_review
- muted
- closed

## Forbidden Behavior

Observation Queue must not:

- does not submit URLs
- does not write CMS
- does not read raw crawler logs
- does not auto-fix issues
- create URL Truth
- mutate URL Truth
- create Search Channel Queue entries
- approve Search Channel Queue entries
- submit URLs
- mutate CMS content
- publish or unpublish content
- read raw crawler logs
- store raw crawler-log fields
- store raw payloads
- expose Metabase
- enable scheduler jobs
- run collector writes
- auto-fix Issue Queue rows
- treat search engine responses as page truth
- treat crawler hits as page truth
- treat Digital PR referral signals as backlink proof

## No-production-mutation Policy

This architecture contract does not run production migrations, write `seo_intel`,
modify CMS records, read production crawler logs, edit production environment,
enable schedulers, submit URLs, deploy code, or call GSC, Baidu, IndexNow, Bing,
360, Sogou, or Shenma APIs.

## Future PR Train Plan

1. SEO-OBS-GOV-01: Observation governance architecture contract.
2. SEO-OBS-GOV-02: Observation queue schema contract.
3. SEO-OBS-GOV-03: Issue severity, dedupe, mute, and SLA contract.
4. SEO-OBS-GOV-04: Entity key and translation group contract.
5. SEO-OBS-GOV-05: `/ops/seo` observation governance display readiness.
6. SEO-OBS-GOV-06: Governance closeout and ledger finalization.

Schema implementation, runtime writers, read-model implementation, dashboard UI
changes, CMS backfills, and production migrations remain separate future work
requiring explicit approval.

## Final Decision

`observation_governance_architecture_contract_ready`

Next task: `SEO-OBS-GOV-02`
