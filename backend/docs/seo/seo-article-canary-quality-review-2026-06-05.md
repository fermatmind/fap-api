# SEO Article Canary Quality Review

Date: 2026-06-05

PR train item: SEO-ARTICLE-CANARY-QUALITY-REVIEW-01

Scope: read-only canary quality review. This report evaluates the first bilingual canary as a process template, not a content template.

## Boundary

No article copy was written or edited. No title, H1, meta, FAQ, CTA, ad, social, or publication copy was generated. No CMS draft was created. No CMS data was mutated. No publish, unpublish, search submission, GSC URL Inspection, Baidu push, IndexNow call, deploy, or private URL access was performed.

## Target Surfaces

| Surface | Status | Decision |
| --- | --- | --- |
| zh public article page | 200 | PASS |
| en public article page | 200 | PASS |
| zh public article API | 200 | PASS |
| en public article API | 200 | PASS |
| zh SEO API | 200 | PASS |
| en SEO API | 200 | PASS |
| sitemap.xml | 200 and contains both canary URLs | PASS |
| llms.txt | 200 and contains both canary URLs | PASS |
| llms-full.txt | 200 and contains both canary URLs | PASS |

## Structured Data

Both public canary pages expose:

- Article schema.
- BreadcrumbList schema.
- FAQPage schema.

Decision: GO for using the canary structured-data flow as a process pattern. The content of FAQ items is not reused by this review.

## Page Metadata Shape

Both locales have a populated browser title and H1. The title and H1 are not exact duplicates in either locale. This review records only structural presence and length ranges, not copy text.

| Locale | Title present | H1 present | Exact duplicate | Note |
| --- | --- | --- | --- | --- |
| zh-CN | yes | yes | no | compact H1, longer title |
| en | yes | yes | no | title and H1 are close in length but not exact duplicates |

Decision: GO for the canary as a metadata process pattern. NO-GO for copying the canary metadata pattern without a per-topic review.

## CTA And Internal Links

Public page checks found article links and test links on both canary pages. The article APIs show:

- published and indexable state,
- related test slug present,
- three related test slugs,
- three article test edges,
- landing surface present,
- answer surface present.

The canary CTA process is reusable only if each future package separately verifies public canonical targets. The first canary does not remove the need for ARTICLE-CTA-ROUTE-GATE-01.

Route note: a page-wide href scan can flag an external social sharing URL containing `/share/`. This is not a FermatMind private route and not an article CTA target, but it proves the later CTA route gate must be host-aware and must distinguish social-share UI from private FermatMind result/share/order/payment routes.

## Claim Boundary

Canary package and postcheck artifacts show claim boundary metadata, package equivalence checks, and controlled publish preflight history. The zh-CN canary previously required explicit claim-warning acknowledgement during controlled publish workflow.

Decision: CONDITIONAL for reuse. The workflow is reusable, but each new topic needs its own claim boundary review and acknowledgement path when warnings exist.

## Public API And SEO API

The article detail APIs expose the expected article, SEO surface, landing surface, and answer surface. The SEO APIs return metadata and structured-data payload surfaces.

Decision: GO for process reuse. Public API health is not a substitute for CMS package review, controlled publish dry-run, or post-publish public smoke.

## Reusable Parts

- Request card to content package handoff pattern.
- Importer/package equivalence discipline.
- Draft noindex and public absence checks before publish.
- Controlled publish preflight and exact approval gate.
- Post-publish public page/API/SEO API smoke.
- Sitemap and llms inclusion checks after publish.
- Article, Breadcrumb, and visible FAQ schema smoke pattern.
- Public canonical test CTA target discipline.

## Not Reusable As-Is

- Article body copy or structure as a content template.
- Final title, H1, meta, FAQ, or CTA copy.
- Topic-specific claim boundaries.
- Topic-specific FAQ decisions.
- Topic-specific internal link graph.
- Search submission readiness.
- Analytics event naming assumptions without ARTICLE-CTA-ROUTE-GATE-01.
- Reviewer/updated/freshness display policy without a separate article metadata gate.

## Decision

GO for using the first canary as a process template.

CONDITIONAL for using it as a field-completeness template, because tracking naming, internal link graph, route-gate host awareness, and reviewer/freshness policy still need repeatable gates.

NO-GO for using it as a copy/content template.

## Next Required Task

Proceed to SEO-ARTICLE-PERFORMANCE-BASELINE-TEMPLATE-01 after this PR merges. Do not create a CMS draft, write article copy, publish, or submit search.

## Validation Commands

```bash
node inline public page/API/schema/sitemap/llms canary signal check
python3 -m json.tool backend/docs/seo/generated/seo-article-canary-quality-review-01.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/docs/seo/seo-article-canary-quality-review-2026-06-05.md backend/docs/seo/generated/seo-article-canary-quality-review-01.v1.json docs/codex/pr-train.yaml docs/codex/pr-train-state.json
git diff --cached --check
```
