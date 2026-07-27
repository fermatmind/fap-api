# MBTI Full Personality Authority Closeout

Date: 2026-07-15

Status: implemented, imported, promoted, verified, and released through the backend authority chain.

This document is the backend technical closeout for the Chinese MBTI public personality cohort. It records the authority model, release gates, operator commands, and verified evidence after the 15-PR full-asset train. It is not a production write authorization and must not be used as one.

> Historical baseline: this document freezes the 52-URL state reached on
> 2026-07-15. The subsequent 45→53 comparison train expanded the cohort to 55
> URLs and is recorded in
> [MBTI Comparison Authority Train 45→53 Closeout](./mbti-comparison-authority-train-45-53-closeout-2026-07-28.md).
> Read the newer closeout for the current comparison projection, release, and
> monitoring contracts. The hashes in either document are evidence receipts,
> not reusable authorization.

## Released cohort

| Surface | Count | Authority |
| --- | ---: | --- |
| A/T personality profiles | 32 | `personality_profiles`, public personality read models, and CMS-backed profile content |
| A/T comparisons | 16 | backend comparison authority and public comparison read models |
| Hot cross-type comparisons | 4 | backend cross-type comparison authority and public comparison read models |
| Total public cohort | 52 | backend public APIs, sitemap authority, and llms authority |

The content review classified 43 records as repaired and 9 records as verify-only. The verify-only records remain part of the same 52-URL release contract; they were not rewritten merely to make every record appear changed.

The current completion claim is limited to the Chinese MBTI cohort above. It does not unlock MBTI x career pSEO, Big Five, Enneagram, RIASEC, private results, attempts, reports, orders, payments, or arbitrary comparison expansion.

## PR train closeout

### fap-web evidence and release gates

| Task | PR | Delivered boundary |
| --- | ---: | --- |
| MBTI-FULL-AUDIT-30 | #1730 | Frozen 52-URL inventory and evidence baseline |
| MBTI-PROFILE-NT-31 | #1731 | NT profile asset package |
| MBTI-PROFILE-NF-32 | #1732 | NF profile asset package |
| MBTI-PROFILE-SJ-33 | #1733 | SJ profile repair and verification package |
| MBTI-PROFILE-SP-34 | #1734 | SP profile repair and verification package |
| MBTI-COMP-AT-35 | #1735 | A/T comparison asset package |
| MBTI-FULL-QA-36 | #1736 | Semantic, duplication, FAQ, and internal-link gate |
| MBTI-CMS-APPROVAL-39 | #1761 | Exact 43-record approval package |
| MBTI-VERIFY-41 | #1764 | 52-record CMS/API read-only verification |
| MBTI-INDEX-43 | #1771 | Double-run canonical, schema, robots, and feed release gate |
| MBTI-GSC-44 | #1772 | Read-only GSC baseline and monitoring cohort |

### fap-api authority and runtime

| Task | PR | Delivered boundary |
| --- | ---: | --- |
| MBTI-CMS-PROFILE-37 | #3027 | 32-profile import dry-run planner |
| MBTI-CMS-COMP-38 | #3030 | 20-comparison import dry-run planner |
| MBTI-CMS-IMPORT-40 | #3049 | Fail-closed draft import, public promotion, and discoverability commands |
| MBTI-PERF-42 | #3085 | Bounded profile public read models, cache, and warmup stability |

GitHub merge state and branch cleanup are operational facts. The public-state truth remains the backend data plus public API readback, not the PR list by itself.

## Authority pipeline

```text
fap-web reviewed asset packages
  -> MBTI-FULL-QA-36 semantic and duplication gate
  -> fap-api profile/comparison dry-run planners
  -> MBTI-CMS-APPROVAL-39 exact approval package
  -> personality:mbti-full-cms-import (draft revisions only)
  -> personality:mbti-full-cms-promote (public content only)
  -> personality:mbti-full-indexability-promote (indexability + sitemap + llms)
  -> fap-api public profile/comparison read models and cache
  -> fap-web authority-only rendering
  -> MBTI-VERIFY-41 and MBTI-INDEX-43 read-only gates
  -> MBTI-GSC-44 read-only monitoring baseline
```

The stages are intentionally independent:

1. **Draft import** may write reviewed revisions but cannot release public content or discoverability.
2. **Public content promotion** may publish the exact reviewed content but cannot change indexability, sitemap, llms, or search state.
3. **Discoverability promotion** may release the exact approved records to indexability, sitemap, and llms, but cannot perform GSC submission, URL Inspection, or search submission.

A successful earlier stage never implies authorization for a later stage.

## Backend public contracts

The public routes are registered under `/api/v0.5`:

```text
GET /api/v0.5/personality
GET /api/v0.5/personality/{type}
GET /api/v0.5/personality/{type}/seo
GET /api/v0.5/personality/comparisons
GET /api/v0.5/personality/comparisons/{comparison}
```

Profiles use four-letter MBTI codes with optional `-a` or `-t` variants. Comparison slugs are supplied by the backend comparison authority. Consumers must not infer missing content, SEO fields, JSON-LD, FAQ, indexability, canonical URLs, or feed eligibility locally.

The public read model is responsible for keeping these states coherent:

- CMS/public content state.
- effective `is_indexable` state.
- final robots directive.
- canonical and SEO surfaces.
- visible FAQ and FAQPage parity.
- backend-authoritative JSON-LD.
- sitemap and llms eligibility.

If authority is unavailable, consumers must fail closed. A local editorial fallback is not an acceptable recovery path.

## Exact batch evidence

The completed 43-record repair batch used these evidence locks:

```text
source_package_sha256=840288581ce02e26afdd40dde1e25cf995fe334791b0a306929a13c76247a78d
import_authorization_payload_sha256=e44d567ad6092d61076ae70009e5cfa39d1d7b3f5b3a78367e0d241a28ede31e
indexability_promotion_package_sha256=e0342ff2fcbd34599831f6560ecba66847e45c8574d02ee0d9b62c448bc14d6f
indexability_authorization_payload_sha256=763ba6a8523d8a1de077a7713586d7c3414331ec34cf62be9e9f99dbfabfdc1d
import_scope_mode=full_chinese_mbti_repair_batch_only
record_count=43
```

These values are immutable historical evidence for this batch. They are not standing authorization, must not be replayed automatically, and must not be treated as a deployment target. Any future import or promotion needs a newly reviewed package, a new dry-run result, exact operator authorization, and current production readiness.

## Operator commands

Run Artisan commands from `backend/`. The safe default is always dry-run.

### Profile dry-run

```bash
php artisan personality:mbti-full-profile-import-dry-run \
  --package=<NT_PACKAGE> \
  --package=<NF_PACKAGE> \
  --package=<SJ_PACKAGE> \
  --package=<SP_PACKAGE> \
  --dry-run \
  --json \
  --output=<PROFILE_PLAN_JSON>
```

### Comparison dry-run

```bash
php artisan personality:mbti-full-comparison-import-dry-run \
  --at-package=<AT_COMPARISON_PACKAGE> \
  --cross-type-package=<CROSS_TYPE_PACKAGE> \
  --dry-run \
  --json \
  --output=<COMPARISON_PLAN_JSON>
```

### Exact mixed-package preflight

```bash
php artisan personality:mbti-full-cms-import \
  --package=<APPROVAL_PACKAGE> \
  --source-package-sha256=<SOURCE_SHA256> \
  --authorization-payload-sha256=<AUTHORIZATION_SHA256> \
  --import-scope-mode=<SCOPE_MODE> \
  --record-count=<RECORD_COUNT> \
  --dry-run \
  --json \
  --output=<IMPORT_PREFLIGHT_JSON>
```

The `--write` mode is separately controlled. Do not derive its hashes from filenames, `main`, a prior batch, or this document. Obtain them from the current reviewed approval artifact and exact operator authorization.

### Read-model warmup

Warm all supported A/T profiles and both public locales:

```bash
php artisan personality:warm-public-read-models
```

Warm a bounded repair cohort:

```bash
php artisan personality:warm-public-read-models \
  --types=istj-a,istp-a,isfp-a,esfj-a \
  --locales=zh-CN
```

The warmup validates detail and SEO reads, a 10-second per-read budget, and a maximum warm detail payload of 524288 bytes. Warmup is read-only with respect to CMS content, but it writes normal application cache entries.

### Read-only API checks

```bash
curl --fail --silent --show-error \
  'https://api.fermatmind.com/api/v0.5/personality/istj-a?locale=zh-CN&org_id=0'

curl --fail --silent --show-error \
  'https://api.fermatmind.com/api/v0.5/personality/istj-a/seo?locale=zh-CN&org_id=0'

curl --fail --silent --show-error \
  'https://api.fermatmind.com/api/v0.5/personality/comparisons/intj-vs-intp?locale=zh-CN&org_id=0'
```

Readback must validate fields and parity, not just HTTP 200.

## Runtime cache contract

`PersonalityPublicReadModelCache` protects the L1 MBTI public surface:

- fresh read-model TTL: 600 seconds.
- last-known-good TTL: 604800 seconds.
- separate detail and SEO cache keys.
- locale, organization, framework, and type/variant are part of the key.
- cache namespace is versioned; consumers must not construct its keys.

The cache is a delivery mechanism, not content authority. A last-known-good response can preserve a previously validated public read model during a transient failure, but it cannot make an unreviewed record public or indexable.

## Verified release evidence

The full train produced the following accepted evidence:

- MBTI-FULL-QA-36: 52/52 passed; no semantic, duplication, FAQ, or deterministic blockers.
- MBTI-VERIFY-41: 52/52 public API readback; all 43 repair records were also verified against CMS authority.
- MBTI-INDEX-43: two consecutive release-gate runs returned `ALLOW_MBTI_52_COMPLETE` with 52/52 for CMS/API, HTTP, canonical, robots, JSON-LD, FAQ parity, sitemap, llms, and llms-full; API timeouts were zero and `PRIVATE_URL_LEAKS=0`.
- MBTI-GSC-44: read-only 28-day baseline for 2026-06-16 through 2026-07-13 recorded 32 clicks, 3106 impressions, 1.0% CTR, average position 9.1, and 106 query rows.

GSC-44 did not submit a sitemap, request indexing, run URL Inspection mutations, or modify CMS/runtime state. At baseline time, page-level GSC rows were not yet observed for the 52-URL cohort. Query evidence must not be imputed to individual pages.

Monitoring review dates recorded by the closeout are 2026-07-22, 2026-07-29, and 2026-08-12. Monitoring is observation, not automatic permission to rewrite titles, FAQ, answer blocks, or content.

## Safety exclusions

The following must never be released through personality sitemap, llms, public internal links, or public analytics identifiers:

- result or report URLs.
- attempt identifiers or recovery URLs.
- order, checkout, payment, or refund URLs.
- private history or share tokens.
- unpublished CMS revisions.
- unsupported or inferred personality/comparison slugs.

The MBTI release gate must report zero private URL leaks. A feed or schema pass cannot compensate for a private URL leak.

## Failure triage

| Symptom | First authority to inspect | Do not do |
| --- | --- | --- |
| Profile API timeout or intermittent 5xx | public read-model cache, payload bounds, warmup, database query path | add frontend editorial fallback |
| API indexable but page noindex | effective robots/SEO read model, frontend authority read, cache age | force local metadata to index |
| Page indexable but absent from sitemap/llms | backend indexability and feed eligibility, feed authority timeout | add a static frontend URL list |
| Comparison missing JSON-LD | backend comparison `jsonld` and FAQ parity | generate a duplicate local schema authority |
| GSC has no page rows | crawl/index observation and review window | infer page performance from query totals |
| Package hash or pre-state mismatch | current approval package and dry-run evidence | bypass or suppress the fail-closed guard |

## Current handoff

The 52-URL Chinese MBTI content and authority train is complete. The default next action is scheduled GSC observation and evidence-led iteration, not another bulk content or import batch.

Open a separate scoped PR/task when evidence identifies one of these needs:

- a CMS content revision.
- a public API or cache defect.
- a canonical, robots, JSON-LD, sitemap, or llms defect.
- a GSC query-to-page mismatch requiring metadata or answer-surface review.
- expansion beyond the frozen 52-URL cohort.

Production CMS/database writes, public promotion, discoverability changes, deployment, and GSC mutations remain separately controlled operations.
