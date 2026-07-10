# ENNEAGRAM-EN13-NOINDEX-RUNTIME-SMOKE-01

## Verdict

`NO_GO` for the publish/cache-warm item.

The core noindex runtime is healthy, but the backend payload and frontend adapter disagree on FAQ and internal-link field names. The current runtime therefore drops all 56 FAQ items and all 39 CMS internal links from visible HTML.

## Passing gates

- API list: 13/13 English Enneagram assets.
- Entity distribution: 1 hub, 3 centers, 9 core types.
- API detail endpoints: 13/13 HTTP 200.
- Public pages: 13/13 HTTP 200.
- CMS state: 13/13 `content_ready`, `is_public=true`, `robots=noindex,follow`.
- Eligibility: 0 index, 0 sitemap, 0 llms, 0 runtime schema eligibility.
- Rich sections: 101 API sections and 101 rendered section nodes; parity on 13/13 pages.
- Method boundary: visible on 13/13 pages.
- Metadata: canonical 13/13, hreflang 13/13, HTML robots noindex/follow 13/13.
- Private boundary: API payload scan and internal FermatMind link scan clean on 13/13 pages.
- Invalid routes: three unsupported public routes and one unsupported API code fail closed with 404.
- Discoverability hold: English EN13 hits are 0 in `sitemap.xml`, `llms.txt`, and `llms-full.txt`.

## Blocking contract mismatch

### FAQ

- Backend API shape: `q` / `a`.
- Frontend adapter accepts: `question` / `answer`.
- API items: 56.
- Adapter-visible items: 0.
- Visible `<details>` items: 0.
- Pages with `FAQPage` JSON-LD: 0.

### Internal links

- Backend API shape: `label` / `url`.
- Frontend adapter accepts: `label` / `href`.
- API items: 39.
- Adapter-visible items: 0.
- Pages rendering the CMS internal-link panel: 0.

## Root-cause boundary

The backend public API returns stored CMS arrays without normalizing the two field families. The fap-web adapter in `lib/cms/personality-public-content-assets.ts` accepts only its canonical `question/answer` and `href` forms, so it filters these otherwise valid EN13 items.

This evidence PR cannot repair the mismatch because its manifest scope permits only generated runtime evidence, train metadata, and sidecar paths. A repair requires a separately authorized contract scope. No repair ID or adjacent train item is invented here.

## Safety boundary

This run was read-only. It did not write CMS data, publish assets, warm sitemap cache, release sitemap/llms/search eligibility, trigger a production deploy, or inspect private result payloads.

## Evidence

- Machine-readable per-route matrix: `runtime-smoke.json`.
- Backend revision: `caf9a7c6c9cd6281c824c660dc160a77c054f31a` from production `REVISION` readback.
- Latest successful frontend production workflow observed before the scan: run `29100165689`, SHA `ba2e91b19af098920ab8fc8e75d06dd8869040e6`.
- Access time: 2026-07-10 22:53 CST.
