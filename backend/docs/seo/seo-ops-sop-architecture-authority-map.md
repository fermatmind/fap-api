# SEO Ops SOP Architecture and Authority Map

Task: SEO-OPS-SOP-01A

Type: docs/generated/test only.

This contract defines the final SEO Ops architecture for routine daily, weekly, and monthly operation. It does not add runtime services, migrations, production operations, scheduler behavior, Search Channel writes, crawler log reads, CMS mutations, Digital PR sends, UI implementation, or fap-web changes.

## Authority Model

CMS/backend is truth for content, SEO metadata, canonical paths, robots/indexability, publish state, claim boundary, and URL Truth.

fap-web is a deterministic public runtime renderer. It may render CMS/API content, stale cache, or minimal shells according to existing product rules, but frontend fallback content is not truth.

seo_intel observes. It stores URL Truth and operational observations, but it does not publish content, rewrite claims, create internal links, or decide CMS truth.

/ops/seo is an operational read-only view. It helps operators inspect URL Truth, Issue Queue, Search Channel Queue, crawler aggregate observations, and SEO safety counters. It is not a truth source.

/ops/seo-operations is a write-capable CMS repair surface behind permissions. It is not the daily read-only observability dashboard and must not be used as a routine checklist surface.

Metabase remains private and read-only. It must not be exposed, iframed, proxied, or treated as a public operational surface.

Search Channel Queue distributes only approved URL Truth. It is not allowed to create URL Truth or submit draft, private, noindex, non-canonical, or claim-unsafe URLs.

Crawler Log observes aggregate crawler behavior only. Raw crawler logs, raw request URIs, IP addresses, user agents, and raw payloads must not become URL Truth.

Observation Governance tracks verification states only. Observation Queue is not URL Truth, not Search Channel Queue, not CMS repair, and not a search submission path.

Content publish rehearsal is dry-run validation. It does not publish, mutate CMS content, enqueue Search Channel, write Observation Queue, or change sitemap/llms behavior.

Internal Link dry-run suggests graph readiness and opportunities. It does not create links, mutate CMS, or use crawler/search/referral signals as link authority.

Chinese Claim Lint flags or blocks unsafe wording. It does not auto-rewrite, auto-publish, or mutate CMS content.

Digital PR tracking is manual and observation-only. Mentions, referrals, and backlinks are signals, not URL Truth.

## URL Truth Effective Connection

URL Truth readback must use the configured SEO Intel connection, not the default
Laravel application connection.

Production rule:

- Effective config key:
  `seo_intel.connection`
- Expected production value:
  `seo_intel`
- Writer code path:
  `App\Services\SeoIntel\UrlTruthInventoryRecordWriter`
- Writer connection:
  `DB::connection(config('seo_intel.connection', 'seo_intel'))`
- Correct authority tables:
  `seo_intel.seo_urls` and `seo_intel.seo_url_entities`
- Non-authority default tables:
  `mysql` / `fap_prod` tables with the same names are not URL Truth authority.

Operational evidence must record the effective URL Truth connection before
classifying retention, drift, missing rows, Search Channel eligibility, or
post-publish propagation readiness.

## Operator Surface Map

- Daily observability: `/ops/seo`.
- CMS repair after human approval: `/ops/seo-operations` or approved CMS/backend workflows.
- Search distribution after approval: Search Channel Queue commands and approved execution path.
- Aggregate crawler review: crawler aggregate observation read model only.
- Content validation: content publish rehearsal dry-run command/output.
- Internal link review: internal link graph dry-run command/output.
- Claim review: Chinese claim linter fixture/candidate output.
- Digital PR review: local/manual tracking artifacts and approved analytics/referral summaries.
- Growth handoff: SEO-GROWTH-MBTI-00 baseline and telemetry contract.

## Routine Ops Boundaries

Truth:

- CMS/backend content state.
- CMS/backend SEO metadata.
- CMS/backend canonical and robots state.
- Backend-governed URL Truth.
- Backend payment/order/report access events for funnel truth.

Observation:

- /ops/seo dashboard counters.
- Issue Queue rows.
- Search Channel Queue states.
- Crawler aggregate observations.
- GSC/GA4/referral feedback when approved and available.
- Digital PR response/referral/mention status.
- Claim lint and internal link dry-run output.

Distribution:

- Search Channel Queue can distribute approved URL Truth only after explicit gate approval.

Repair:

- CMS/backend repair workflows only after human review.
- No repair action originates from the read-only /ops/seo dashboard.

## Forbidden Routine Authority Sources

- frontend fallback is not truth.
- static sitemap is not truth.
- static llms is not truth.
- crawler log is not truth.
- search engine response is not truth.
- Digital PR mention is not truth.
- local copy is not truth.

## Forbidden Routine Operations

- no runtime implementation in this SOP train.
- no migrations.
- no production operations.
- no env edits.
- no deployment.
- no scheduler activation.
- no collector writes.
- no crawler log reads.
- no Search Channel submission.
- no CMS content mutation.
- no article publish.
- no Metabase exposure.
- no fap-web modification.
- no Digital PR send.
- no auto-rewrite.
- no auto-link creation.
- no pSEO generation.
- no RIASEC, Big Five, or Career Graph precise recommender overclaiming.

## MBTI Growth Loop Handoff

The SOP train prepares the system for SEO-GROWTH-MBTI-00. The handoff must keep MBTI as the first governed growth loop and must not scale to Big Five, RIASEC, or Career before the MBTI baseline, telemetry, claim gate, Search Channel canary, Digital PR observation, and 7/14/28-day review are complete.

Next task after this PR: `SEO-OPS-SOP-01B`.
