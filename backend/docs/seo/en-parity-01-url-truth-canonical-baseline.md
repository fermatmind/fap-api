# EN-PARITY-01 URL Truth / Hard 404 / Soft 404 / Canonical Baseline

## Scope

EN-PARITY-01 converts the EN-PARITY-00 broken public URL inventory into a backend authority baseline. This PR does not create content bodies, mutate production CMS, deploy, run production migrations, submit URLs, or use fap-web fallback as content authority.

## Authority Decision

Backend/CMS URL Truth is the authority layer for public/indexable content assets. Runtime observations from fermatmind.com are evidence, but they are not the source of truth.

The homepage canonical policy is:

- `https://fermatmind.com/` is the zh-CN home canonical.
- `https://fermatmind.com/en` is the English home canonical.
- `/zh` must not compete as a second zh-CN canonical URL.

## Implemented Baseline

Backend URL Truth now understands published `content_pages` as CMS-backed authority rows. A content page is eligible only when it is:

- `org_id = 0`
- `status = published`
- `is_public = true`
- `is_indexable = true`
- locale is `en` or `zh-CN`
- title is non-empty
- `content_md` or `content_html` is non-empty
- canonical path is locale-safe

The backend sitemap generator now uses the same content page eligibility rule and does not emit draft, empty, or locale-mismatched content page URLs.

## EN-PARITY-00 P0 Handling

The prior scan found hard/soft broken URLs:

- `/en/about`
- `/en/support`
- `/en/help/about`
- `/en/help/for-business-and-research`
- `/en/method-boundaries`
- `/zh/about`
- `/zh/careers`
- `/zh/help/contact`
- `/zh/support`
- `/en/research/mbti-personality-types-salary-turnover-report`
- `/zh/research/mbti-personality-types-salary-turnover-report`

This PR establishes the backend authority rule for those paths:

- Content pages may be exposed only when a real published CMS/content_pages row exists.
- Missing help/content pages must stay absent from URL Truth and sitemap exposure until authority exists.
- MBTI research apex URLs remain eligible only when safe published `research_reports` authority rows exist.
- Frontend gateway-only pages are not treated as backend content authority.

## Deferred

- fap-web runtime status, redirects, and metadata must be handled in a linked frontend PR if public runtime still diverges after backend authority exists.
- Full content body creation belongs to EN-PARITY-03.
- Translation group matrix and counterpart lookup belong to EN-PARITY-02.

## Validation

Focused test:

```bash
cd backend && php artisan test --filter=EnParity01UrlTruthCanonicalBaselineTest --no-ansi
```

Common backend validation:

```bash
cd backend && php artisan route:list --no-ansi
cd backend && vendor/bin/pint --test
cd backend && composer validate --strict
cd backend && composer audit --locked --no-interaction --ignore-unreachable
python3 -m json.tool backend/docs/seo/generated/en-parity-01-url-truth-canonical-baseline.v1.json >/dev/null
git diff --check
```
