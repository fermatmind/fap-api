# RIASEC V2 Sitemap / LLMS Convergence Diagnosis Fix

Date: 2026-06-08

Scope: article ids 40 and 41 discoverability convergence after controlled publish.

## Decision

CONDITIONAL. Backend publish/unpublish now invalidates the backend discoverability caches that can hide newly published articles from sitemap-source and backend XML sitemap generation. Production convergence still requires backend deployment and post-deploy smoke checks.

## Diagnosis

- `Article::publiclyIndexable()` is the backend authority for article sitemap inclusion.
- Public article APIs can expose article ids 40 and 41 while `/api/v0.5/seo/sitemap-source` still serves a stale cached payload.
- The backend sitemap-source cache uses separate fresh and stale keys.
- The backend XML sitemap cache uses separate XML and ETag keys.
- Before this fix, `ArticlePublishService` updated article publish state but did not flush either backend discoverability cache layer.

## Fix

- Added a shared SEO discoverability cache invalidator.
- Article publish now flushes sitemap-source fresh/stale caches and backend XML sitemap XML/ETag caches after successful audit logging.
- Article unpublish now flushes the same cache layers after the database transaction commits.
- The sitemap-source warm command now reuses the shared controller cache constants instead of duplicate string literals.

## Not Changed

- No article body, title, H1, meta, FAQ, CTA, or CMS content was changed.
- No CMS draft mutation was performed.
- No publish action was performed.
- No search submission was performed.
- No frontend deploy or revalidation was performed.
- No private, result, order, share, pay, payment, history, tokenized, or user-specific URLs were accessed.

## Required Follow-Up Validation

After backend deployment of this fix:

1. Warm or request `/api/v0.5/seo/sitemap-source` and verify both RIASEC article canonical URLs are present.
2. Verify `X-Fermat-Cache` is not serving a pre-fix stale payload.
3. Verify public `sitemap.xml` convergence through the frontend/public sitemap authority.
4. Verify `llms.txt` and `llms-full.txt` contain both public canonical article URLs.
5. Only after all surfaces converge should search submission preflight be considered.
