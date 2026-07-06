# Big Five Dimension Pages Entity Graph Matrix

Date: 2026-07-06
Scope: read-only runtime matrix for Big Five dimension public pages

## Route Shape

Observed public route shape:

- `/zh/personality/big-five/{dimension}`
- `/en/personality/big-five/{dimension}`

Non-owner or not-proven shapes from spot checks:

- `/zh/personality/openness` returned 404.
- `/en/personality/openness` returned 404.
- `/api/v0.5/personality-content-assets/big-five/dimension/openness` returned 404.
- `/api/v0.5/personality-content-assets/big-five/openness` returned 404.

## Matrix

| Dimension | zh public route | zh status | en public route | en status |
| --- | --- | ---: | --- | ---: |
| Openness | `/zh/personality/big-five/openness` | 200 | `/en/personality/big-five/openness` | 200 |
| Conscientiousness | `/zh/personality/big-five/conscientiousness` | 200 | `/en/personality/big-five/conscientiousness` | 200 |
| Extraversion | `/zh/personality/big-five/extraversion` | 200 | `/en/personality/big-five/extraversion` | 200 |
| Agreeableness | `/zh/personality/big-five/agreeableness` | 200 | `/en/personality/big-five/agreeableness` | 200 |
| Neuroticism | `/zh/personality/big-five/neuroticism` | 200 | `/en/personality/big-five/neuroticism` | 200 |

## Repository Evidence

- `ScaleRegistrySeeder` defines `BIG5_OCEAN` and describes the five OCEAN dimensions.
- Big Five result-page agent docs exist, but they are private result-surface contracts and do not substitute for public profile SEO/GEO authority.
- `PersonalityBigFivePublicProfileAgentPromote` exists and describes promotion of Big Five public profile CMS draft assets with no index/search side effects.

## Runtime Evidence Method

Commands used:

```bash
curl -L -s -o /dev/null -w '%{http_code} %{url_effective}' https://www.fermatmind.com/zh/personality/big-five/openness
curl -L -s -o /dev/null -w '%{http_code} %{url_effective}' https://www.fermatmind.com/en/personality/big-five/openness
```

The batch check repeated equivalent zh/en public route checks for all five dimensions.

## Remaining Gaps

Still requires separate proof:

- canonical/hreflang parity for each dimension;
- sitemap/llms inclusion or exclusion correctness;
- visible internal-link graph between Big Five test owner, dimensions, result guide, and career/support pages;
- claim boundary review on all dimension pages;
- schema parity if structured data is intended.
