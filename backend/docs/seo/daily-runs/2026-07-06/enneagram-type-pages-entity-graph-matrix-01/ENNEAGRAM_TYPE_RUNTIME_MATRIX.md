# Enneagram Type Pages Entity Graph Matrix

Date: 2026-07-06
Scope: read-only runtime matrix for Enneagram type public pages

## Route Shape

Observed public route shape:

- `/zh/personality/enneagram/type-{number}`
- `/en/personality/enneagram/type-{number}`

Non-owner or not-proven shapes from type 1 spot checks:

- `/zh/personality/enneagram/1` returned 404.
- `/en/personality/enneagram/1` returned 404.
- `/zh/personality/enneagram-1` returned 404.
- `/en/personality/enneagram-1` returned 404.
- `/zh/personality/type-1` returned 404.
- `/en/personality/type-1` returned 404.
- `/zh/personality/enneagram/reformer` returned 404.
- `/en/personality/enneagram/reformer` returned 404.

## Matrix

| Type | zh public route | zh status | en public route | en status |
| --- | --- | ---: | --- | ---: |
| Type 1 | `/zh/personality/enneagram/type-1` | 200 | `/en/personality/enneagram/type-1` | 200 |
| Type 2 | `/zh/personality/enneagram/type-2` | 200 | `/en/personality/enneagram/type-2` | 200 |
| Type 3 | `/zh/personality/enneagram/type-3` | 200 | `/en/personality/enneagram/type-3` | 200 |
| Type 4 | `/zh/personality/enneagram/type-4` | 200 | `/en/personality/enneagram/type-4` | 200 |
| Type 5 | `/zh/personality/enneagram/type-5` | 200 | `/en/personality/enneagram/type-5` | 200 |
| Type 6 | `/zh/personality/enneagram/type-6` | 200 | `/en/personality/enneagram/type-6` | 200 |
| Type 7 | `/zh/personality/enneagram/type-7` | 200 | `/en/personality/enneagram/type-7` | 200 |
| Type 8 | `/zh/personality/enneagram/type-8` | 200 | `/en/personality/enneagram/type-8` | 200 |
| Type 9 | `/zh/personality/enneagram/type-9` | 200 | `/en/personality/enneagram/type-9` | 200 |

## Repository Evidence

- `ScaleRegistrySeeder` defines the Enneagram scale with primary slug `enneagram-personality-test-nine-types`.
- `SitemapSourceController` includes Enneagram test landing source candidates, not type-page graph proof.
- Enneagram result-page asset and private result services exist, but private result contracts do not substitute for public profile SEO/GEO graph completion.

## Runtime Evidence Method

Commands used:

```bash
curl -L -s -o /dev/null -w '%{http_code} %{url_effective}' https://www.fermatmind.com/zh/personality/enneagram/type-1
curl -L -s -o /dev/null -w '%{http_code} %{url_effective}' https://www.fermatmind.com/en/personality/enneagram/type-1
```

The batch check repeated equivalent zh/en public route checks for all nine Enneagram types.

## Remaining Gaps

Still requires separate proof:

- canonical/hreflang parity for each type page;
- sitemap/llms inclusion or exclusion correctness;
- visible internal-link graph between Enneagram test owner, type pages, result guide, and support pages;
- claim boundary review on all type pages;
- schema parity if structured data is intended.
