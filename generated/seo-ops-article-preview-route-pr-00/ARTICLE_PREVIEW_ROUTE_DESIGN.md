# Article Preview Route Design

## Decision

Implement as a fap-api-only PR.

Chosen path:
- Add authenticated Ops route: `/ops/article-preview/{article}`
- Add Article edit page action: `Preview draft`
- Render a backend noindex/no-store preview page from the Article working revision
- Do not modify fap-web, sitemap, llms, search queues, ISR, schema, hreflang, or publish state

## Why fap-api-only

The preview need is operator QA inside the CMS/Ops surface. A backend Ops route can reuse existing admin session, org context, and CMS read authorization while directly setting response headers. This avoids a two-repo PR and avoids weakening the public article route.

## Route

```text
GET /ops/article-preview/{article}
Route name: ops.articles.preview
```

## Auth boundary

The route is registered only when the Ops/Admin panel is enabled and uses:

- `SetOpsRequestContext`
- `AdminAuth`
- `ResolveOrgContext`
- `EnsureAdminTotpVerified`
- `RequireOpsOrgSelected`
- `OpsAccessControl`
- `EnsureCmsAdminAuthorized:read`

## Data source

The controller reads only:

- `articles`
- `article_translation_revisions` via `workingRevision`
- `article_seo_meta`
- category/tags display metadata

No user, order, payment, result, session, or token data is read.

## Preview rendering

The preview page renders:

- title
- excerpt
- markdown body from working revision if present
- SEO title / description snapshot
- canonical metadata value as text only
- public URL candidate as text only
- safety state rail

It intentionally does not emit:

- canonical link tag
- hreflang / alternate link tag
- Article JSON-LD
- FAQ JSON-LD
- Breadcrumb JSON-LD
- analytics payload

## Preview URL for Draft ID 42

```text
https://ops.fermatmind.com/ops/article-preview/42
```

This is an Ops-authenticated preview URL, not a public canonical URL.
