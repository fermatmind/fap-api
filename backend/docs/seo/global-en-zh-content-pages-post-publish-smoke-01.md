# GLOBAL-EN-ZH-CONTENT-PAGES-POST-PUBLISH-SMOKE-01 Report

## 1. Executive Summary
The controlled CMS publish succeeded, but the public frontend smoke did not fully pass. Production CMS and apex API show all five Wave 1 English content pages as published/public, but frontend runtime remains unstable because the server-side API base appears to fall back to `https://api.fermatmind.com`, which returned TLS EOF during smoke checks.

Final decision: `content_pages_post_publish_smoke_completed_with_api_base_blocker`.

## 2. CMS Published State
All five records are published in production CMS:

| Page | Status | Public | Indexable | Published | Canonical path |
| --- | --- | --- | --- | --- | --- |
| `brand` | `published` | `true` | `false` | `true` | `/en/brand` |
| `careers` | `published` | `true` | `false` | `true` | `/en/careers` |
| `charter` | `published` | `true` | `false` | `true` | `/en/charter` |
| `foundation` | `published` | `true` | `false` | `true` | `/en/foundation` |
| `policies` | `published` | `true` | `false` | `true` | `/en/policies` |

Foundation retained `planned_public_benefit_shareholding` meaning and had zero guarded phrase hits.

## 3. API Runtime Check
Apex same-origin API checks passed for all five pages via `https://fermatmind.com/api/v0.5/content-pages/{slug}?locale=en&org_id=0`.

Backend API host observation failed for `https://api.fermatmind.com/api/v0.5/content-pages/{slug}?locale=en&org_id=0` with TLS EOF errors. This was recorded as observation only and no DNS/nginx/env change was made.

## 4. Public Frontend Runtime Check
Frontend route checks were unstable. During smoke collection, all target routes were either 200 with content but stale `Page Not Found` metadata/missing canonical, or returned 404 from another request pass.

| Page | Latest status | Title | H1 | Canonical |
| --- | --- | --- | --- | --- |
| `brand` | `200` | `Page Not Found | FermatMind` | `Brand and Usage Guidelines` | `None` |
| `charter` | `200` | `Page Not Found | FermatMind` | `FermatMind Editorial Charter` | `None` |
| `foundation` | `404` | `Page Not Found | FermatMind` | `None` | `None` |
| `careers` | `404` | `Page Not Found | FermatMind` | `None` | `None` |
| `policies` | `404` | `Page Not Found | FermatMind` | `None` | `None` |


## 5. Canonical / Robots / Claim Boundary
Robots were safe for non-indexable publish because `noindex` was present. Canonical was missing for routes affected by stale frontend metadata. No staging canonical or private-flow marker was detected. Foundation public content did not expose forbidden foundation phrases when rendered.

## 6. Sitemap / llms / Footer Safety
No target page appeared in:

- `sitemap.xml`
- `llms.txt`
- `llms-full.txt`
- `/en` footer/nav surface

This matches the publish boundary: public CMS records are live, but discoverability exposure remains disabled.

## 7. Search Channel Safety
No Search Channel queue item exists for the five content pages. Queue items 2 and 3 remained unchanged, and no live submission was detected for these pages. Gates remained closed/not enabled.

## 8. Runtime Blocker Diagnosis
Suspected blocker: `api_host_tls_or_base_url_issue`.

Evidence:

- Apex same-origin API returned 200 for all five content pages.
- `api.fermatmind.com` returned TLS EOF from the smoke environment.
- Node1 PM2 env observation showed `NEXT_PUBLIC_API_URL` empty.
- fap-web server-side API base defaults to `https://api.fermatmind.com` when `NEXT_PUBLIC_API_URL` is empty.
- Content page route returns `notFound()` when server-side CMS fetch returns null/fails.

## 9. Validation
Required local validation was run for the report PR; see PR section and generated JSON for command evidence.

## 10. PR / Merge Result
Pending in this branch at report-generation time.

## 11. Sidecar Issues
Frontend runtime repair is required before discoverability exposure readiness. The repair should address the production frontend API base/TLS path without adding frontend fallback content authority.

## 12. What Was Not Done
No CMS mutation, publish, deploy, Search Channel action, URL submission, external search API call, sitemap/llms/footer/nav exposure, env/DNS/nginx edit, migration, raw log access, production user data access, or fap-web mutation was performed.

## 13. Final Decision
`content_pages_post_publish_smoke_completed_with_api_base_blocker`

## 14. Next Task
`GLOBAL-EN-ZH-CONTENT-PAGES-FRONTEND-RUNTIME-REPAIR-01`
