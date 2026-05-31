# CAREER-1046-INTERNAL-LINKING-AUTHORITY-01

## 1. Executive Summary

This PR records the backend-authoritative internal linking policy for the
Career 1046 public detail surface.

The current authority is backend Career services, not frontend fallback copy:

- `CareerFirstWaveOccupationCompanionLinksService`
- `CareerFirstWaveRecommendationCompanionLinksService`
- `CareerFirstWaveNextStepLinksService`

The policy keeps internal links bounded to public, indexable, release-gated
Career authority. It does not generate MBTI x Career L3 pages, pSEO pages,
static cross-matrix pages, Search Channel items, or frontend fallback content.

## 2. Link Families

| Family | Authority service | Runtime status |
| --- | --- | --- |
| occupation companion links | `CareerFirstWaveOccupationCompanionLinksService` | backend authority |
| recommendation companion links | `CareerFirstWaveRecommendationCompanionLinksService` | backend authority |
| next-step links | `CareerFirstWaveNextStepLinksService` | backend authority |

## 3. Eligibility Rules

Career detail link targets must satisfy all of these before exposure:

- public runtime projection exists
- `detail_route_enabled=true`
- `robots_indexable=true`
- `release_gate_pass=true`
- target is not private, draft, fallback-only, noindex, 404, or manual-hold
- target is not one of the excluded slugs

Excluded slugs remain blocked from internal link authority:

- `software-developers`
- `digital-forensics-analysts`
- `computer-occupations-all-other`

## 4. Non-Generated L3 Boundary

This PR does not create static MBTI x Career, Big Five x Career, RIASEC x
Career, or other cross-matrix content. Future L3 work must use a dynamic slot
architecture with explicit authority and budget controls.

## 5. Claim Boundary

Internal links may support exploration and navigation only. They must not imply:

- best career for the user
- hiring fit
- job suitability guarantee
- salary guarantee
- career success guarantee
- diagnosis, treatment, or cure
- psychometric prediction of job success

## 6. Validation

Focused validation:

- `php artisan test --filter=Career1046InternalLinkingAuthority01 --no-ansi`

The test validates the generated artifact, existing authority service
versions, excluded slug boundaries, no-generation flags, and report sections.

## 7. What Was Not Done

- No production write.
- No static L3 generation.
- No fap-web change.
- No Search Channel enqueue.
- No URL submission.
- No sitemap or llms exposure change.

## 8. Final Decision

`career_1046_internal_linking_authority_completed_ready_for_frontend_discovery_ux`

## 9. Next Task

`CAREER-1046-FRONTEND-DISCOVERY-UX-01`
