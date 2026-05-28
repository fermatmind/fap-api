# DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_REPAIR-01

## Executive Summary

This task creates a repo-backed, non-runtime replacement authority source package for the `detail_ready_1048` rollout path.

Final decision: `source_repair_completed_ready_for_controlled_import`.

The selected replacement source is `digital-forensics-analysts`. It is backed by the existing backend display-asset mapper source:

- `CareerSelectedDisplayAssetMapper::COHORT_003_SLUGS`
- US SOC: `15-1299`
- O*NET-SOC 2019: `15-1299.06`

This PR does not import it into production authority, does not publish runtime pages, and does not expose sitemap, llms, footer, or Search Channel surfaces.

## Why This Source

`DETAIL_READY_1048_REPLACEMENT_AUTHORITY_RESELECT-01` found no eligible replacement row already present in production authority. The prior candidate, `computer-occupations-all-other`, was already indexable and therefore invalid as a replacement.

The source-repair path is therefore to create a controlled, reviewable source package for one non-CN, O*NET/SOC-backed replacement candidate before any controlled import or rollout manifest regeneration.

## Replacement Candidate

- slug: `digital-forensics-analysts`
- English title: `Digital Forensics Analysts`
- Chinese title: `数字取证分析师`
- family: `computer-and-information-technology`
- source system: O*NET-SOC 2019
- source code: `15-1299.06`
- SOC code: `15-1299`
- source status: repo-backed source repair package only

## Controlled Import Boundary

The import package is non-runtime and non-published. A future controlled import task must validate the target authority environment before any write:

- candidate is outside the current 1048 union
- candidate is non-indexable before import
- candidate is not `software-developers`
- candidate is not manual hold
- candidate is not blocked
- candidate is not a CN proxy
- candidate has no existing indexable `index_states`
- candidate has no sitemap / llms / footer / Search Channel exposure

## Claim Boundary

The package uses occupation-reference framing only. It must not be expanded into:

- precise career recommendation
- best career for a user
- hiring fit
- job suitability guarantee
- career success prediction
- salary guarantee
- MBTI determines career
- RIASEC ranks best career
- Big Five predicts job performance

## Boundaries Preserved

- No CMS mutation
- No production DB write
- No runtime promotion
- No publish
- No deploy
- No Search Channel action
- No URL submission
- No frontend fallback authority
- No sitemap / llms / footer exposure
- `software-developers` remains on manual hold

## Next Task

Recommended next task:

`DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT-01`

Purpose: run a controlled import for the source-repair package after explicit approval in the target authority environment. Only after that succeeds should the train return to:

`DETAIL_READY_1048_DELTA_AUTHORITY_REPAIR-01`
