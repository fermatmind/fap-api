# ENNEAGRAM-LLMS-TXT-PRODUCTION-REVALIDATION-01

- Checked at: `2026-07-12T11:43:14.695016Z`
- Target host: `https://fermatmind.com`
- Frontend deployed SHA: `eaa5a1ab0ba7860b18f470d975f0da4ed3c3ba9d`
- Conclusion: `GO_LLMS_TXT_PRODUCTION_REVALIDATED`

## Feed counts

| Surface | HTTP | Cache-Control | Enneagram URLs | Expected |
|---|---:|---|---:|---:|
| `/llms.txt` | 200 | `public, s-maxage=3600, stale-while-revalidate=86400` | 116 | 116 |
| `/llms-full.txt` | 200 | `public, s-maxage=3600, stale-while-revalidate=86400` | 0 | 0 |

## llms.txt Enneagram distribution

| Dimension | Count | Expected |
|---|---:|---:|
| locale:en | 58 | 58 |
| locale:zh | 58 | 58 |
| entity:hub | 2 | 2 |
| entity:center | 6 | 6 |
| entity:core_type | 18 | 18 |
| entity:wing | 36 | 36 |
| entity:instinctual_subtype | 54 | 54 |

## Safety counters

| Check | Count |
|---|---:|
| llms.txt duplicates | 0 |
| llms.txt malformed URLs | 0 |
| llms.txt non-apex hosts | 0 |
| llms.txt forbidden pattern hits | 0 |
| llms.txt missing expected URLs | 0 |
| llms.txt unexpected URLs | 0 |
| llms-full Enneagram leakage | 0 |

## Side effects

All side-effect counters are recorded as zero: CMS writes, eligibility writes, Search Queue writes, IndexNow submissions, deploys, and cache warm actions.

## Canonical evidence

The JSON report contains the full 116-row canonical evidence table with locale, entity type, code, path, and URL.
