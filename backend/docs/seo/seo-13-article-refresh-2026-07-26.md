# SEO 13-article refresh

## Scope

This package refreshes exactly 13 existing public `zh-CN` CMS Articles. It changes reader-facing title, excerpt, Markdown body, SEO title, and SEO description through isolated working revisions. It does not create or rename a route and does not change schema, hreflang, indexability, sitemap, llms, Search Channel, media, category, tags, deployment, or frontend fallback authority.

The work is split into six tasks:

1. Six editorial owners: AI attitude, AI coach safety, love theory, Valentine's planning, INFJ male visibility, and childhood career aspirations.
2. Two MBTI owners: result interpretation and behavior experiments.
3. Three IQ owners: product instructions, result interpretation, and reasoning practice.
4. Two Big Five owners: five-dimension combination narrative and growth hub.
5. Exact 13-record update packages, local contract validation, claim boundaries, and authenticated-preview handoff.
6. One controlled release window: draft update, review approval, per-record promotion, public readback, and closeout.

Tasks 1–5 are repository work. Task 6 is a separately controlled production/CMS operation and must use fresh deployed-state preflight evidence and the exact confirmations emitted for the resulting working revisions.

## Authority and immutable package

Package root:

```text
backend/docs/seo/import-packages/seo-13-article-refresh-2026-07-26
```

The committed authority files are:

- `cohort.json`: exact IDs, locale, translation groups, observed public revision IDs, query owners, title/meta/excerpt, claim boundaries, and source URLs.
- `cohort.lock.json`: deterministic SHA-256 lock for the cohort, target set, every package file, and the complete content set.
- one child package per slug, using the existing `articles:update-existing-seo-content-package` schema.

Current deterministic content-set SHA-256:

```text
b58959e613d6abdf1123da09811f7c78c87c73f1e26b70ef3d542506d089432e
```

This SHA is local package evidence only. Production execution must regenerate the lock on the deployed release and require an exact match. A public revision, article identity, command revision, package file, or pre-state change invalidates the prior approval packet.

## Query-owner separation

| Article | Unique owner |
| --- | --- |
| `how-personality-shapes-attitude-toward-ai` | personality, AI trust, AI anxiety, control, task risk |
| `how-16-personality-types-talk-to-an-ai-coach` | safe AI coaching use, sycophancy, privacy, calibration |
| `which-love-script-fits-you-best` | Sternberg love triangle and relationship states |
| `best-valentines-date-by-personality-and-relationship-science` | Valentine's date planning by stage, goal, stimulation, and constraints |
| `are-infj-men-rare-or-socially-silenced` | INFJ male rarity claims, samples, gender norms, visibility bias |
| `childhood-dream-job-still-shapes-career-choice` | childhood career aspirations translated into adult work-structure hypotheses |
| `mbti-narrative-portrait` | reading and validating E/I, S/N, T/F, J/P results |
| `mbti-growth-guide` | MBTI-style four-week behavior experiments |
| `iq-test-tool-guide` | FermatMind 30-question product instructions and limits |
| `iq-test-narrative-portrait` | interpreting one online reasoning result |
| `iq-test-growth-guide` | four-week reasoning strategy practice |
| `big-five-narrative-portrait` | OCEAN dimension-combination narratives |
| `big-five-growth-guide` | Big Five growth hub and behavior experiments |

The broad owners remain unchanged:

- `mbti-basics` owns “what MBTI is.”
- `iq-test-score-and-limits-explained` owns general online-IQ score and formal-test boundaries.
- `big-five-tool-guide`, `ocean-traits-what-the-five-letters-mean`, and `how-to-read-big-five-results-without-self-labeling` own Big Five foundations and basic result reading.

## Local generation and validation

Regenerate deterministic package metadata:

```bash
cd <REPOSITORY_ROOT>
node backend/scripts/seo/build_seo_13_article_refresh_packages.mjs
```

Validate the exact package contract:

```bash
cd <REPOSITORY_ROOT>/backend
php artisan test --filter=Seo13ArticleRefreshPackageTest --no-ansi
```

The contract test creates 13 existing published articles in an isolated test database, runs the official updater dry-run for every package, executes the draft-only update path, proves the published revision and discoverability state stay unchanged, and verifies:

- exact ID, locale, slug, translation-group, and canonical locks;
- 13 unique query owners;
- at least 2,000 visible Han characters per article;
- page-specific quick answers, FAQs, and source sections;
- no body H1, generic legacy FAQ, `Evidence Note**`, private URL, or forbidden draft marker;
- schema, hreflang, search, revalidation, sitemap, and llms holds.

## Production stage 1: deployed-state preflight

Do not execute production commands from a local checkout or an unapproved release. After the exact merged SHA is deployed through the protected backend deployment workflow, regenerate the package lock in the active release and require the committed `content_set_sha256`.

For every cohort row, run the official update dry-run with the exact values from `cohort.json`:

```bash
cd <DEPLOYED_BACKEND_RELEASE>/backend
php artisan articles:update-existing-seo-content-package \
  --package=docs/seo/import-packages/seo-13-article-refresh-2026-07-26/<SLUG> \
  --article-id=<ARTICLE_ID> \
  --translation-group-id=<TRANSLATION_GROUP_ID> \
  --locale=zh-CN \
  --expected-slug=<SLUG> \
  --expected-canonical=https://fermatmind.com/zh/articles/<SLUG> \
  --dry-run \
  --json \
  --slug-lock \
  --canonical-lock \
  --schema-hold \
  --hreflang-hold \
  --search-hold \
  --no-revalidation \
  --no-sitemap \
  --no-llms
```

The 13 dry-runs must all return `ok=true`, `action=would_update_existing_working_revision`, `active_surface_guard_scan.status=passed`, and zero errors. Production Article identity and current public revision IDs must be read back again; the values observed on 2026-07-26 are evidence, not reusable write locks.

The exact dry-run outputs, deployed SHA, release identity, content-set SHA, current 13 public revision IDs, and target-set SHA form the draft-update approval artifact. No draft write may occur before the operator explicitly authorizes that exact artifact.

## Production stage 2: isolated working-revision update

After exact draft-update authorization, rerun the same 13 commands with `--execute` instead of `--dry-run`. Stop the cohort immediately on the first error. This stage may create or update only isolated `human_review` working revisions. It must prove:

- published revision IDs are unchanged;
- public title/body/SEO remain unchanged;
- existing `index,follow`, sitemap, and llms state remain unchanged;
- each import record uses `seo_content_package_existing_article_update`;
- exactness passes and media/graph remain `unchanged_hold`;
- no search, schema, hreflang, revalidation, sitemap, or llms action occurs.

## Production stage 3: authenticated preview and editorial approval

For every new working revision:

1. open the authenticated `/ops/article-preview/<ARTICLE_ID>` preview;
2. compare title, excerpt, body, SEO title, and SEO description with the exact package;
3. verify visible Markdown, tables, internal anchors, source URLs, and mobile readability;
4. confirm no forbidden marker, private URL, unsupported percentile, official-affiliation implication, diagnosis, hiring use, or outcome guarantee;
5. bind the authenticated preview result to the exact revision set through `.github/workflows/seo-13-article-review-approval-production-ops.yml`;
6. obtain the receipt-bound operator phrase emitted by its read-only `preflight`;
7. run `apply` only for that immutable state; the streamed runner uses the active release's existing `ArticleTranslationWorkflowService::approveEditorialWorkingRevision` and repository `solo_owner` attestation policy;
8. record the exact article ID, working revision ID, reviewer, reviewed timestamp, approved timestamp, body hash, and preview result.

Approval is review evidence only. It does not authorize publication.

The generic Filament editorial-review queue is not used for this cohort because it intentionally enumerates `status=draft` records. These are already-published articles with isolated working revisions, so changing their public Article status merely to expose them in that queue would violate the existing-route hold.

## Production stage 4: controlled promotion

The promotion cohort is now a fixed atomic batch. Do not loop the legacy
single-article command 13 times: that can leave a partially published cohort.
The active backend release must contain the batch-capable command and the
protected production workflow before any production preflight.

### Legacy metadata bootstrap prerequisite

Production promotion preflight run `30384203988`, attempt `1`, failed closed
with zero writes. Its sanitized error set identified one shared legacy-data
condition on article IDs `5`, `6`, `7`, `9`, and `10`: the old published
records have no `article_seo_meta` row, category assignment, or tag mappings.
The reported canonical and locale lock failures are downstream consequences
of the absent SEO-meta authority, not working-revision content drift.

Before promotion preflight is retried, use the separately protected
`.github/workflows/seo-13-legacy-metadata-bootstrap-production-ops.yml`.
Its read-only `preflight` locks the exact five article, published-revision,
working-revision, taxonomy, and current public-content identities. Its
receipt-bound `apply` performs one transaction containing exactly:

- 5 SEO-meta inserts using each current old published Article title, excerpt,
  cover image, and locked self-canonical;
- 5 category assignments using existing active taxonomy;
- 21 existing-tag mappings;
- 1 private audit record.

The bootstrap does not modify article bodies, revision fields or statuses,
publication, indexability, schema, hreflang, revalidation, sitemap/llms
eligibility, Search Channel, GSC, URL Inspection, queues, or deployment. A
partial pre-state, taxonomy drift, article/revision drift, state-hash drift,
or any non-exact write count fails the whole transaction. After its apply
receipt and readback pass, rerun the full 13-article promotion preflight; the
bootstrap receipt alone does not authorize promotion.

Run the batch dry-run against the exact active release:

```bash
cd <DEPLOYED_BACKEND_RELEASE>/backend
php artisan articles:promote-existing-working-revision \
  --batch=seo13-20260726 \
  --expected-target-count=13 \
  --dry-run \
  --json
```

The repository control plane is
`.github/workflows/seo-13-article-atomic-promotion-production-ops.yml`.
Its `preflight` mode is read-only and binds the current production release,
all 13 article/revision/slug/canonical identities, reader-facing content and
SEO hashes, eligibility holds, the target set, and the content set. It emits
one immutable apply phrase:

Before either promotion mode can run, workflow eligibility also downloads and
validates the immutable successful review-approval apply receipt from run
`30231516428`, attempt `1`. That receipt binds the exact 13 approved working
revision IDs and editorial-review evidence to content set
`b58959e613d6abdf1123da09811f7c78c87c73f1e26b70ef3d542506d089432e`.
Authenticated-preview QA is independently recorded in the immutable
`docs/seo/evidence/seo-13-authenticated-preview-qa-2026-07-27.json` contract,
whose locked SHA-256 is
`d8ec2e4ba7bbc3c920cadcddfb7dabf5c632a006bb168c7ce51fee8b888f1fa9`.
It binds all 13 article IDs, slugs, `zh-CN` locales, working revision IDs,
title/body hashes, one rendered H1, visible quick-answer/FAQ/reference
sections, and the noindex/no-store preview boundary.
The deployed command independently verifies the committed cohort lock and
every package file hash, then compares each live `zh-CN` working revision's
title, excerpt, body, SEO title, SEO description, canonical, slug, and
translation group against that cohort. Any receipt, package, locale, or
reader-facing field drift fails before a write.

```text
I explicitly approve SEO 13 atomic public promotion for SHA <RELEASE_SHA> release <RELEASE_NAME> preflight run <RUN_ID> attempt <ATTEMPT> state <STATE_SHA> revision set <REVISION_SET_SHA> content set b58959e613d6abdf1123da09811f7c78c87c73f1e26b70ef3d542506d089432e; publish exactly 13 approved working revisions and keep schema, hreflang, search, revalidation, sitemap eligibility, llms eligibility, GSC, URL Inspection, and deploy changes held.
```

After the operator confirms that exact phrase, `apply` downloads and validates
the immutable preflight receipt. The deployed command rechecks the approved
state under deterministic row locks and promotes all 13 revisions in one
outer database transaction:

```bash
cd <DEPLOYED_BACKEND_RELEASE>/backend
php artisan articles:promote-existing-working-revision \
  --batch=seo13-20260726 \
  --expected-target-count=13 \
  --expected-state-sha256=<STATE_SHA> \
  --expected-revision-set-sha256=<REVISION_SET_SHA> \
  --confirm="<COMMAND_CONFIRMATION_DERIVED_FROM_THE_APPROVED_RECEIPT>" \
  --preview-approved \
  --schema-hold \
  --hreflang-hold \
  --search-hold \
  --no-revalidation \
  --no-sitemap \
  --no-llms \
  --execute \
  --json
```

The atomic transaction updates only the article projections, the 13 new
published revisions, the 13 previous revision statuses, and the working
revision SEO title/description fields. It records one batch audit inside the
same transaction. Follow-up dispatch, frontend revalidation, discoverability
cache invalidation, schema/hreflang release, search, sitemap/llms eligibility
writes, GSC, URL Inspection, and deploy actions remain held. Any identity,
content, SEO, approval, import-gate, claim, private-URL, or readback failure
rolls back the whole batch.

## Production stage 5: public readback

For every slug, verify public API and rendered HTML:

```bash
curl --fail --silent --show-error \
  "https://api.fermatmind.com/api/v0.5/articles/<SLUG>?locale=zh-CN"

curl --fail --silent --show-error \
  "https://fermatmind.com/zh/articles/<SLUG>"
```

Required acceptance:

- HTTP 200 from public API and page;
- article ID, slug, locale, new published revision ID, title, excerpt, and body match the approved working revision;
- SEO title and description match the package;
- canonical remains the same apex `/zh/articles/<SLUG>` route;
- robots remains `index,follow`;
- sitemap remains included and llms exposure remains allowed;
- hreflang does not change;
- visible body includes the page-specific quick answer, FAQ, and source section;
- no `draft candidate`, `pending manual review`, `Evidence Note**`, legacy generic FAQ, private route, or sensitive query key;
- all 25 committed internal links return HTTP 200;
- Big Five article descriptions no longer expose the forbidden review marker;
- the six prior `Evidence Note**` rendering artifacts are absent.

Run read-only closeout per article:

```bash
cd <DEPLOYED_BACKEND_RELEASE>/backend
php artisan articles:release-closeout \
  --article-id=<ARTICLE_ID> \
  --expected-slug=<SLUG> \
  --json
```

GSC Request Indexing, URL Inspection, sitemap submission, Search Channel enqueue, and any discoverability mutation are explicitly excluded. SEO observation may be scheduled separately after the released URL Truth and public smoke evidence pass.

## Repository rule impact

CMS/backend Article records remain the only public content and SEO authority. The frontend receives no fallback copy. Existing routes, canonicals, discoverability eligibility, media authority, and private-flow exclusions remain unchanged. The generator is a deterministic repository helper for the exact package; it performs no CMS, database, deploy, or search write.

## Production draft control plane

The workflow `.github/workflows/seo-13-article-draft-production-ops.yml` is the only Codex-assisted production lane for creating this cohort's working revisions.

1. Run `preflight` from the exact latest `main` control-plane SHA against the exact active application SHA and release name. This phase is read-only and emits an immutable receipt containing the 13 current published-revision locks, proposed body hashes, a state SHA, and the exact apply approval phrase.
2. Obtain the operator's verbatim confirmation of that emitted phrase.
3. Run `apply` with the successful preflight run id, attempt, state SHA, and exact phrase. Eligibility downloads and validates the immutable receipt, and the remote runner repeats all 13 preflights before its first CMS write.
4. Apply creates exactly 13 isolated `human_review` working revisions. It does not promote any revision and keeps schema, hreflang, search submission, revalidation, sitemap, and llms changes held.

Authenticated preview QA, editorial approval, controlled promotion, public smoke, and closeout remain later, separately evidenced phases. A successful draft receipt does not authorize or prove publication.

## Production draft and preview evidence

- active application SHA: `de9865c8cdde21a6359b60052f426f867abe0ead`
- active release: `seo-13-article-refresh-20260727-de9865c8-30215056008-1`
- draft preflight: run `30222641581`, attempt `1`
- draft preflight state: `e1426e1ce08db8a0388a424a069ef33bff0a86283f303b7d29327154b341e743`
- draft apply: run `30228428454`, attempt `1`, `PASS_APPLY`
- applied revision set: `883c4b62c51c35be9364862d29dae3261e94323151bdd8231d53c11274d5dbb5`
- authenticated preview QA: 13/13 exact working revision IDs matched; 13/13 titles matched; each page rendered exactly one H1 and the visible `快速答案`, `常见问题`, and `参考来源` sections
- visible Han-character range: 2,111–2,460 per article
- preview boundary: `noindex,noarchive,nosnippet`, `Draft preview only`, no-store
- forbidden draft/review markers: 0
- private URL guard findings: 0
- images missing alt text: 0
- editorial review approval apply: run `30231516428`, attempt `1`, `PASS_APPLY`
- review-approved working revisions: 13/13
- review approval writes: 13
- publication, schema, hreflang, search, revalidation, sitemap, and llms remained held

This evidence authorizes neither deployment nor promotion by itself. The
atomic promotion control plane must first be deployed through the separately
authorized exact-SHA backend release lane. Its production preflight must then
rediscover and bind the exact live approved revision set before the operator
confirms the emitted promotion phrase.
