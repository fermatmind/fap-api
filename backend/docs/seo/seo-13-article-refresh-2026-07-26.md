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
5. approve the exact working revision through the existing Filament editorial-review workflow under the repository `solo_owner` policy;
6. record the exact article ID, working revision ID, reviewer, reviewed timestamp, approved timestamp, body hash, and preview result.

Approval is review evidence only. It does not authorize publication.

## Production stage 4: controlled promotion

Run a fresh dry-run for each approved working revision:

```bash
cd <DEPLOYED_BACKEND_RELEASE>/backend
php artisan articles:promote-existing-working-revision \
  --article-id=<ARTICLE_ID> \
  --working-revision-id=<WORKING_REVISION_ID> \
  --current-published-revision-id=<CURRENT_PUBLISHED_REVISION_ID> \
  --translation-group-id=<TRANSLATION_GROUP_ID> \
  --expected-slug=<SLUG> \
  --expected-canonical=https://fermatmind.com/zh/articles/<SLUG> \
  --dry-run \
  --json
```

Each successful dry-run emits its exact confirmation:

```text
I explicitly approve Codex to promote article id <ARTICLE_ID> working revision <WORKING_REVISION_ID> after preflight passes.
```

The operator must confirm the exact 13 emitted phrases for the exact working revisions. Then execute each promotion in the same controlled release window:

```bash
cd <DEPLOYED_BACKEND_RELEASE>/backend
php artisan articles:promote-existing-working-revision \
  --article-id=<ARTICLE_ID> \
  --working-revision-id=<WORKING_REVISION_ID> \
  --current-published-revision-id=<CURRENT_PUBLISHED_REVISION_ID> \
  --translation-group-id=<TRANSLATION_GROUP_ID> \
  --expected-slug=<SLUG> \
  --expected-canonical=https://fermatmind.com/zh/articles/<SLUG> \
  --confirm="<EXACT_CONFIRMATION_FROM_PREFLIGHT>" \
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

The runtime is per-article rather than transactionally atomic across all 13 records. “One full release” therefore means one locked cohort and one controlled release window. It does not permit skipping a failed record or publishing an unreviewed remainder.

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
