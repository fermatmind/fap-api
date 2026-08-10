# Assessment Catalog Product Truth Convergence 01

Status: `ASSESSMENT_CATALOG_FACT_AUTHORITY_CONVERGENCE_01_CODE_COMPLETE`

This change binds the public assessment catalog and lookup contract to the approved Window 3 product decision:

- product-truth contract SHA-256: `3d9bef9549374007cedb5859cd14a90566020d5322ff47a12764bc6504269857`
- source-manifest SHA-256: `ba7b58afaa1228c445e138d5d5d5e5fa90db7f5222dfea19ee493be536a3b2ae`
- authority owner: fap-api registry, config, SKU catalog, and public API projection

## Public contract

| Scale | Default form | Other form | Default questions | Default minutes |
| --- | --- | --- | ---: | ---: |
| MBTI | `mbti_144` | `mbti_93` | 144 | 15 |
| BIG5_OCEAN | `big5_120` | `big5_90` | 120 | 15 |
| ENNEAGRAM | `enneagram_likert_105` | `enneagram_forced_choice_144` | 105 | 12 |
| RIASEC | `riasec_60` | `riasec_140` | 60 | 8 |
| IQ_RAVEN | `IQ_OWNER_ORIGINAL_30` | — | 30 | 20 |
| EQ_60 | `eq_60` | — | 60 | 10 |

All six public projections are `FREE` / `free_only`, expose no unlock or upgrade SKU and no offer, and force `blur_others=false` plus `teaser_percent=0`. The free-only registry contract grants full report access and suppresses paywall/CTA output. Unsupported catalog ratings are projected as zero. MBTI organization-scoped Pro, Gift, and Credit SKUs remain active in the commerce layer but are filtered from the public assessment SKU endpoint.

Report-unlock SKU records are retained as inactive historical compatibility data. The forward-only migration updates only org-0 registry rows and attempt-scoped report-unlock SKUs; tenant registry rows and Clinical/SDS commerce are outside this scope.

## Claim boundary

MBTI copy describes preferences rather than fixed identity or career outcomes. Big Five copy describes continuous traits and states that FermatMind does not currently publish specific reliability, validity, norm, or percentile evidence. IQ copy describes matrix-reasoning self-evaluation and does not claim fixed intelligence or potential.

## Deferred activation

This PR does not deploy, execute the migration, invalidate production caches, write CMS, or perform production readback. Hub/Category refresh remains gated until a separately controlled production deployment, migration, cache closeout, and en/zh public API readback succeed.
