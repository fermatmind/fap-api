# PR-HIRING-01

## 1. Executive Summary

This PR prepares a backend-owned, non-runtime hiring content authority package
for three first-wave roles connected to the Career 1046 growth foundation:

- technical partner / engineering lead
- product design and brand systems lead
- growth SEO and content operations lead

The package is draft/import-only. It does not publish pages, mutate production
CMS, expose footer links, enqueue Search Channel, submit URLs, or use fap-web
fallback content as authority.

## 2. Authority Boundary

The intended authority layer is backend `content_pages` plus an operator
reviewed import package. The frontend may render approved CMS content later,
but it must not become the source of hiring copy.

The existing English content-page baseline already contains a `careers` node,
so this PR does not add runtime content-page schema keys.

## 3. Draft Roles

| Role key | Status | Runtime exposure |
| --- | --- | --- |
| `technical-partner-engineering-lead` | `draft_review_only` | false |
| `product-design-brand-systems-lead` | `draft_review_only` | false |
| `growth-seo-content-operations-lead` | `draft_review_only` | false |

Each draft contains role intent, responsibilities, review notes, and claim
boundaries. Compensation, title, location, contract terms, and legal hiring
language require human approval before any CMS import or publish task.

## 4. Discoverability Boundary

The draft package must remain absent from:

- sitemap
- `llms.txt`
- `llms-full.txt`
- footer/nav links
- Search Channel queue
- public runtime pages

## 5. Claim Boundary

The package avoids unsupported claims about:

- guaranteed compensation or employment terms
- equity transfer or partnership grants
- hiring suitability guarantees
- career success guarantees
- clinical, diagnosis, treatment, or cure outcomes
- salary prediction or psychometric job success prediction

## 6. Validation

Focused validation:

- `php artisan test --filter=PrHiring01ContentAuthority --no-ansi`

The test validates the generated artifact, import package, no-exposure flags,
role count, claim boundary markers, and report sections.

## 7. What Was Not Done

- No production CMS mutation.
- No runtime publish.
- No deployment.
- No fap-web footer/nav change.
- No Search Channel enqueue.
- No URL submission.

## 8. Final Decision

`pr_hiring_content_authority_package_ready_for_human_review`

## 9. Next Task

`CAREER-1046-INTERNAL-LINKING-AUTHORITY-01`
