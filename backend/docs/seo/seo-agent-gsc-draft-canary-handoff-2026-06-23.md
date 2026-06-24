# SEO Agent GSC Draft Canary Handoff - 2026-06-24 Closeout

## Purpose

This document records the completed FermatMind SEO Agent GSC cohort article
canary loop from the June 22-24 production runs. It is the operator and Codex
handoff for future SEO Agent cohorts.

It summarizes the current capabilities, authority boundaries, production
evidence, approval gates, and known SOP corrections. It does not add runtime
code, mutate CMS, publish articles, write URL Truth, enqueue Search Channel
items, submit URLs, change sitemap or `llms.txt`, enable scheduler, start queue
workers, deploy, or call live GSC/model APIs.

## Executive Status

The first real GSC cohort article canary is closed end to end.

Completed:

- 7 of 7 GSC article candidates were drafted, repaired where needed, QA'd,
  published through single-article canaries, written into URL Truth, enqueued to
  Search Channel, approved, live-submitted through IndexNow, and closed out.
- 7 of 7 IndexNow live submissions were accepted.
- Train-level closeout evidence was generated.
- URL Truth retention was rechecked against the correct authority connection.

Train closeout artifact:

- Path:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/gsc-cohort-train-closeout/20260624T-gsc-article-cohort-indexnow-closeout/seo-agent-gsc-article-cohort-train-closeout-20260624T020600Z.json`
- Sha256:
  `0dc3e644bda92ea329436fb5c82a1174b69556bd7ac03d6d0add7fcdf09ed249`
- Status:
  `success_artifact_backed_with_current_url_truth_db_probe_gap`
- Important superseding note:
  the URL Truth probe gap in that artifact was caused by checking the default
  `mysql` / `fap_prod` connection instead of the URL Truth authority connection
  `seo_intel`.

URL Truth authority root-cause artifact:

- Path:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/url-truth-retention-readback/20260624T-gsc-article-cohort-current-db/seo-agent-url-truth-authority-retention-root-cause-20260624T022900Z.json`
- Sha256:
  `1f3a741302e04841aeb4e42f443ee13a42d5e2bcc5719628139cba9d5f6dc2c2`
- Status:
  `success_previous_default_mysql_probe_superseded`
- Root cause:
  `readback_connection_mismatch`
- Correct authority:
  `config('seo_intel.connection') = seo_intel`
- Current retained rows:
  `seo_intel.seo_urls=144`, `seo_intel.seo_url_entities=144`
- Closed cohort retained:
  7 of 7 rows/entities.

## Source Cohort

The completed package is the PR-B GSC cohort draft package:

- Package path:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/gsc-cohort-handoff/20260622T-prb-d5078205-real-dryrun/seo-agent-cms-draft-package-dry-run-20260622T092803Z.json`
- Package sha256:
  `889108891858699267f825351335cb8094c7733dcec169966f50bba2e0bdf416`
- Candidate order:
  1. `article:41:en`
  2. `article:37:zh-CN`
  3. `article:34:en`
  4. `article:3:zh-CN`
  5. `article:40:zh-CN`
  6. `article:8:zh-CN`
  7. `article:51:zh-CN`

## Current Capability Boundary

The SEO Agent is a controlled, evidence-backed semi-automated pipeline.

Supported capabilities:

1. Ingest sanitized GSC cohort artifacts.
2. Convert cohort artifacts into SEO Agent source, aggregate, Codex handoff,
   Codex verdict, and CMS draft package dry-run artifacts.
3. Write bounded CMS article draft revisions only after exact human approval.
4. Append bounded draft payload repair revisions for approved single targets.
5. Run read-only readback QA, claim-risk QA, preview/runtime QA, batch QA, and
   publish gate readiness.
6. Publish one article canary only after separate exact publish approval.
7. Run read-only post-publish propagation planning.
8. Export/import bounded URL Truth artifacts only after separate URL Truth write
   approval.
9. Bridge published article evidence into Search Channel queue planning and
   enqueue only after separate enqueue approval.
10. Approve queue items and live-submit IndexNow only after separate queue and
    live-submit approvals.
11. Produce per-article and train-level closeout evidence.

Not supported as automatic behavior:

- automatic CMS draft writes
- automatic draft repairs
- automatic CMS publish
- automatic URL Truth import
- automatic Search Channel enqueue
- automatic queue approval
- automatic IndexNow or other live search submission
- automatic sitemap or `llms.txt` mutation
- automatic scheduler activation
- automatic queue worker start
- automatic external model calls
- live GSC API reads/writes from the SEO Agent lane
- frontend content changes

The system is not a fully automatic publisher. Every production mutation remains
a separate exact-approval gate.

## Authority Model

CMS/backend remains truth for:

- article content
- article SEO title and description
- FAQ payload chosen for public output
- internal-link proposal payload after write approval
- publish state
- public/indexable flags
- canonical path
- claim boundary

URL Truth authority is the `seo_intel` connection, not the default Laravel
`mysql` connection:

- Writer code path:
  `App\Services\SeoIntel\UrlTruthInventoryRecordWriter`
- Effective connection:
  `DB::connection(config('seo_intel.connection', 'seo_intel'))`
- Production config:
  `config('seo_intel.connection') = seo_intel`
- Correct readback tables:
  `seo_intel.seo_urls`, `seo_intel.seo_url_entities`
- Default app DB:
  `mysql` / `fap_prod`
- Important rule:
  do not validate URL Truth retention by reading `fap_prod.seo_urls`; it is not
  the URL Truth authority table.

Search Channel Queue distributes approved URL Truth only. It is not allowed to
create URL Truth or submit draft, private, noindex, non-canonical, or
claim-unsafe URLs.

GSC readmodel and GSC exported artifacts are observation inputs. They are not
CMS truth, URL Truth, or search-submission authority.

## Completed Article Inventory

| Target | Canonical URL | Source ArticleRevision | Live ArticleTranslationRevision | URL Truth row/entity | Queue item | IndexNow |
| --- | --- | ---: | ---: | ---: | ---: | --- |
| `article:41:en` | `https://fermatmind.com/en/articles/what-is-riasec-holland-code-career-interest-test` | 71 | 67 | 34 | 256 | accepted |
| `article:37:zh-CN` | `https://fermatmind.com/zh/articles/mbti-vs-holland-career-choice` | 81 | 71 | 13 | 260 | accepted |
| `article:34:en` | `https://fermatmind.com/en/articles/mbti-personality-test-science-vs-pseudoscience` | 80 | 70 | 17 | 259 | accepted |
| `article:3:zh-CN` | `https://fermatmind.com/zh/articles/big-five-tool-guide` | 74 | 68 | 23 | 257 | accepted |
| `article:40:zh-CN` | `https://fermatmind.com/zh/articles/riasec-holland-career-interest-test-explained` | 75 | 69 | 18 | 258 | accepted |
| `article:8:zh-CN` | `https://fermatmind.com/zh/articles/mbti-basics` | 84 | 72 | 40 | 261 | accepted |
| `article:51:zh-CN` | `https://fermatmind.com/zh/articles/enneagram-personality-test-explained` | 85 | 73 | 43 | 262 | accepted |

## Per-Article Closeout Evidence

### `article:41:en`

- Closeout:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/article41-closeout/20260623T-article41-full-closeout/seo-agent-article41-post-publish-search-closeout-20260623T045500Z.json`
- Closeout sha256:
  `2138d2785353b1de033f4ad84def994d4de52619b5c366535af7da22b4b55ad2`
- Publish evidence sha256:
  `1325f446a453471d61546128033210c9aa3cefbe77dc8ba1ab68bc9aff977359`
- URL Truth handoff sha256:
  `c440ee9722c65d3b0ae18034f0e048391ea2791fb4aa1cfb2f56029a2dbcb4de`
- Bounded IndexNow live evidence sha256:
  `f53a02990d0f3426236dad6400952acabab7578027984098a6af5d8a212771f6`

### `article:3:zh-CN`

- Closeout:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/article-closeout/20260623T-article3-indexnow-closeout/seo-agent-article3-indexnow-closeout-20260623T103600Z.json`
- Closeout sha256:
  `36acf287e673f46e5fb4ae9a748b0585d4b5abbb879a4cdfd78336994627f95f`
- Publish evidence sha256:
  `9c863f4342532c8691a7c7819d124885f9bd97f54ab32b39e1aa601e139658d1`
- URL Truth handoff sha256:
  `c57e56aacc20b1075913aa9f7779ebaad525d3e758c84548bca493a5cc35d5a6`
- Queue item:
  `257`

### `article:40:zh-CN`

- Closeout:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/article-closeout/20260623T-article40-indexnow-closeout/seo-agent-article40-indexnow-closeout-20260623T115542Z.json`
- Closeout sha256:
  `7f0754b70ab56dca7f7959b19dca8099a2a3e7f9ce4deb379761fac924ae87e5`
- Publish evidence sha256:
  `ed1fbd28d7a794ccc3af09b0b4a18ab0826ecf44ae6b5c74483cf9041426b505`
- URL Truth handoff sha256:
  `d7d253b32e3b096bd751a3454c1d269ffe4535d660fc74d31f144ce76dc77394`
- Queue item:
  `258`

### `article:34:en`

- Closeout:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/article-closeout/20260623T-article34-indexnow-closeout/seo-agent-article34-indexnow-closeout-20260623T131720Z.json`
- Closeout sha256:
  `335658593ef048744734776d84039d0c0346f0fa8f2ed44bbef34ffcafe63e33`
- Publish evidence sha256:
  `da70712f5bda39839da75898c63e275f7bc4b4817365ced1ba4044f20ac7b1ba`
- URL Truth handoff sha256:
  `47010c51b6813f5c9489c276482d726a5205fa31f1ae67de0d64bcf503bcf59d`
- Queue item:
  `259`

### `article:37:zh-CN`

- Closeout:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/article-closeout/20260623T-article37-indexnow-closeout/seo-agent-article37-indexnow-closeout-20260623T140459Z.json`
- Closeout sha256:
  `267762777bd29d01b23c7ca8a72578f53d06071fa1af960d834e0c987ac4a300`
- Publish evidence sha256:
  `9f8a1b8c29569d97e28167a17f16deacd2e6b6742f32a6ef3aa16dc944a16b3a`
- URL Truth handoff sha256:
  `1aebcc5c4a20c4de4c60e3efb36efd1abff3f6c669fa624705991ebffa61995b`
- Queue item:
  `260`

### `article:8:zh-CN`

- Closeout:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/article-closeout/20260624T-article8-indexnow-closeout/seo-agent-article8-indexnow-closeout-20260624T012458Z.json`
- Closeout sha256:
  `b6db7b52bda741fc5b8e1dd50c42e35e50eef4e8299b4789559aef7f67f0c1dd`
- Publish evidence sha256:
  `d6476b1b330746c52a36901e12bb8cbb5349ca90c8aac116963ef6b650577ceb`
- URL Truth handoff sha256:
  `77c26dffae1f7251e48265556ee94f2a0823ac441ac5721c0e275393df3297c7`
- Queue item:
  `261`

### `article:51:zh-CN`

- Closeout:
  `/var/www/fap-api/shared/backend/storage/app/seo-agent/article-closeout/20260624T-article51-indexnow-closeout/seo-agent-article51-indexnow-closeout-20260624T015001Z.json`
- Closeout sha256:
  `1aaf535d95fdab97eeb0af21d8353e2e108e5d89a2f1f5616a6b7267a2c18ae4`
- Publish evidence sha256:
  `98eafaf6fa2106304429cff3dd0047f0094d885f685ecdd7514cab214586c910`
- URL Truth handoff sha256:
  `05ce15d7358725017671ec74b0fdd903a2bd252b98b0014640272d4dd11cbcb7`
- Queue item:
  `262`

## Command Families

### Cohort input and proposal chain

- `seo-agent:gsc-cohort-handoff`
- `seo-agent:codex-review-runner`
- `seo-agent:cms-draft-package-dry-run`
- `seo-agent:gsc-remaining-candidate-batch-plan`

These commands create source, aggregate, handoff, verdict, and draft package
artifacts. They do not write CMS or publish.

### CMS draft and repair chain

- `seo-agent:cms-draft-write`
- `seo-agent:cms-draft-payload-repair-canary`
- `seo-agent:cms-draft-readback-qa`
- `seo-agent:article-draft-claim-risk-qa`
- `seo-agent:article-draft-preview-runtime-qa`
- `seo-agent:gsc-batch-draft-qa-support`
- `seo-agent:gsc-draft-publish-gate-readiness`

Draft write and repair require separate exact approvals. QA and gate readiness
are read-only.

### Publish chain

- `seo-agent:article-cms-publish-canary`

This command publishes one article canary only after exact approval. It must not
write URL Truth, enqueue search, submit IndexNow, mutate sitemap, mutate
`llms.txt`, start scheduler, or start queue workers.

### Post-publish propagation chain

- `seo-agent:article-post-publish-propagation-dry-run`
- `seo-intel:url-truth-handoff`
- `seo-agent:post-publish-search-submit`
- `seo-intel:search-channel-approve`
- `seo-intel:search-channel-submit-approved`

URL Truth write, Search Channel enqueue, queue approval, and IndexNow live
submission are separate gates.

### Observation chain

- `seo-agent:gsc-post-publish-feedback`
- `seo-intel:gsc-readmodel-import-dry-run`
- `seo-intel:gsc-readmodel-import-canary`
- `seo-intel:gsc-readmodel-canary-readback`

The GSC readmodel lane is upstream evidence. It must remain separate from CMS
draft/publish/search mutation.

## Required Gate Order for a New Cohort

Use this order for each new cohort or article canary:

1. GSC export/classification input is sanitized.
2. `seo-agent:gsc-cohort-handoff` read-only dry-run succeeds.
3. Draft package quality is reviewed.
4. Exact CMS draft write approval is obtained.
5. Draft write executes with a bounded limit.
6. Readback QA succeeds.
7. Claim-risk QA succeeds.
8. Preview/runtime QA succeeds.
9. Publish gate readiness is `publish_ready`.
10. Exact single-article publish approval is obtained.
11. Publish canary dry-run succeeds.
12. Publish execute succeeds.
13. Post-publish propagation dry-run succeeds.
14. URL Truth export/import dry-run succeeds.
15. Exact URL Truth import/write approval is obtained.
16. URL Truth write executes or is idempotently confirmed.
17. Search bridge dry-run succeeds.
18. Exact Search Channel enqueue approval is obtained.
19. Enqueue execute creates or idempotently confirms one queue item.
20. Exact queue approval is obtained.
21. Queue approval executes.
22. Exact bounded live IndexNow approval is obtained.
23. Live submit executes.
24. Per-article closeout evidence is generated.
25. Train-level closeout inventory is generated.

Do not batch publish. Do not combine publish, URL Truth, Search Channel, and
live submit in the same approval.

## Claim and Content Safety Rules

The article canary loop must block or require review for:

- clinical or diagnostic claims
- guaranteed outcomes
- ranking or certainty claims
- hiring-fit overclaims
- career prediction overclaims
- unsupported source claims
- locale mismatch
- semantic drift from the existing article
- unsafe internal-link action
- placeholder FAQ or placeholder TDK payload

Chinese TDK and FAQ require extra review because translated or query-matching
phrases can sound more certain than intended.

FAQ repair should append a new draft revision. Do not mutate historical draft
revisions in place.

## Search and URL Truth Safety Rules

URL Truth:

- Must be public.
- Must be indexable.
- Must be non-private.
- Must be backend-authoritative.
- Must be read from the `seo_intel` connection.
- Must not be inferred from frontend fallback, crawler log, search result, GSC
  result, or local copy.

Search Channel:

- May enqueue only approved URL Truth.
- Must keep draft/private/noindex/non-canonical/claim-unsafe URLs out.
- Must keep enqueue separate from live submit.
- May requeue after a new publish only when URL Truth lastmod/content fingerprint
  is newer than a submitted queue item.

IndexNow:

- Live submit requires explicit bounded approval for the exact queue item and
  channel.
- No Google Indexing API, Baidu submit, sitemap submit, scheduler activation,
  or queue worker start is implied by IndexNow approval.

## Known SOP Correction

The early train-level closeout artifact recorded a current URL Truth DB probe
gap because the probe looked at `mysql` / `fap_prod`. That probe is superseded.

Correct URL Truth readback SOP:

1. Read `config('seo_intel.connection')`.
2. Connect through that connection.
3. Read `seo_urls` and `seo_url_entities` there.
4. Record the effective connection in the evidence artifact.
5. Only then classify retention.

Evidence proving the correction:

- `seo-agent-url-truth-authority-retention-root-cause-20260624T022900Z.json`
- Sha256:
  `1f3a741302e04841aeb4e42f443ee13a42d5e2bcc5719628139cba9d5f6dc2c2`

Recommended small follow-up PR:

- Add effective URL Truth connection metadata to future URL Truth readback,
  closeout, or train inventory artifacts.
- Update operator SOP wording so `fap_prod.seo_urls` is never used as URL Truth
  retention truth.

## GSC Readmodel Boundary

The GSC readmodel foundation is upstream infrastructure for SEO Agent decision
evidence, not CMS drafting or publishing work.

Readmodel allowed work:

- consume sanitized GSC artifacts
- dry-run bounded import readiness
- exact-approved controlled import canaries
- readback/idempotency evidence

Readmodel forbidden work:

- CMS draft write
- CMS publish
- URL Truth write
- Search Channel enqueue
- IndexNow or any live search submission
- sitemap or `llms.txt` mutation
- scheduler activation
- frontend mutation

## Negative Guarantees for This Handoff

This document update performs no production operation and no runtime mutation:

- no CMS write
- no CMS publish
- no URL Truth write
- no sitemap or `llms.txt` mutation
- no Search Channel enqueue
- no live search submission
- no indexing request
- no scheduler activation
- no queue worker start
- no external model API call
- no live GSC API call
- no frontend mutation

## Recommended Next Steps

1. Open a small docs/contract PR that makes URL Truth readback evidence state
   the effective connection explicitly.
2. Start the next GSC cohort only after that SOP correction is merged or at
   least understood by the operator.
3. Keep the same gate discipline: draft, repair, publish, URL Truth, Search
   Channel, queue approval, and live submit remain separate exact approvals.

## Suggested Follow-up Prompt

```text
Implement a small backend docs/contract PR that updates URL Truth readback and
closeout evidence wording to report effective_url_truth_connection=seo_intel.
Do not change runtime behavior, do not write CMS, do not write URL Truth, do not
enqueue Search Channel, and do not submit search. Validate docs JSON and
git diff only.
```
