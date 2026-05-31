# CAREER-1046-OBSERVABILITY-SLO-01

## 1. Executive Summary

This PR records the read-only observability/SLO baseline for the production
Career runtime after the 1046-detail rollout.

The long-term operating baseline is:

- EN Career jobs API: `1046`
- ZH Career jobs API: `1046`
- runtime public Career slugs: `1046`
- localized EN/ZH Career detail URLs: `2092`
- sitemap Career detail URLs: `2092`
- `llms.txt` Career detail URLs: `2092`
- complete `llms-full.txt` Career detail URLs: `2092`

This is an observability artifact only. It does not deploy, mutate production
data, warm or rewrite caches, enqueue Search Channel, or submit URLs.

## 2. SLO Baselines

| Surface | Expected value |
| --- | ---: |
| EN Career API jobs | 1046 |
| ZH Career API jobs | 1046 |
| Runtime public Career slugs | 1046 |
| Localized Career detail URLs | 2092 |
| Sitemap Career URLs | 2092 |
| `llms.txt` Career URLs | 2092 |
| Complete `llms-full.txt` Career URLs | 2092 |

Excluded slugs must remain absent from API, sitemap, `llms.txt`, and
`llms-full.txt`:

- `software-developers`
- `digital-forensics-analysts`
- `computer-occupations-all-other`

## 3. Priority Conditions

P0 conditions:

- public Career API count regression
- excluded slug leakage
- sitemap or `llms.txt` missing Career URLs
- private/noindex URL exposure
- Career detail runtime 404 for a public slug

P1 conditions:

- `llms-full.txt` degraded response rate above budget
- slow Career API response budget breach
- stale public authority cache
- metadata/indexability drift

P2 conditions:

- minor metadata copy drift
- non-core visual mismatch
- non-blocking internal link gap

## 4. Observation Cadence

Recommended operating cadence:

- Day 0: post-deploy smoke
- Day 1: Career API, sitemap, `llms.txt`, `llms-full.txt`, and sample detail check
- Day 3: trend and degraded artifact review
- Day 7: Search Channel GO/HOLD review

Search Channel remains closed unless a future explicitly approved task opens it.

## 5. Safety Boundaries

Not performed:

- production write
- cache mutation
- database mutation
- CMS mutation
- deployment
- Search Channel enqueue
- URL submission
- external search API call
- fap-web change

Career language remains bounded to occupation information, workstyle context,
exploratory guidance, interest signals, and decision support.

## 6. Validation

Focused validation:

- `php artisan test --filter=Career1046ObservabilitySlo01 --no-ansi`

The test validates the generated artifact, required no-write flags, expected
counts, excluded slug boundaries, and consistency with the
`CareerRuntimeReadModelService`.

## 7. Final Decision

`career_1046_observability_slo_completed_ready_for_hiring_content_authority`

## 8. Next Task

`PR-HIRING-01`
