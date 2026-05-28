# DETAIL_READY_1048_REPLACEMENT_AUTHORITY_RESELECT-01

## Executive Summary

This task re-ran the replacement candidate selection after the previously selected replacement, `computer-occupations-all-other`, was found to be already indexable in production authority.

Final decision: `blocked_no_eligible_replacement_candidate`.

No new controlled import package was generated because no candidate satisfied the required replacement gates.

## Selection Gates

The replacement candidate must be:

- outside the current 1048 detail-ready union
- non-indexable
- not `software-developers`
- not manual-hold
- not blocked
- not a CN proxy / CN directory row
- backed by O*NET/SOC authority, or safely importable through the controlled O*NET/SOC authority path
- compatible with controlled v4.2 public display asset import
- not exposed in sitemap, llms, footer, or runtime in this PR

## Read-Only Production Authority Evidence

Command used:

```bash
php artisan career:audit-detail-ready-1048-candidates --json --output=/tmp/detail-ready-1048-reselect-scan.json
```

Observed counts:

- current public detail: 30
- union detail ready: 1048
- ready not currently public: 1018
- manual-hold ready: 1
- raw occupation assets: 2786
- outside-union non-indexable rows: 1543
- outside-union non-indexable rows with O*NET-SOC 2019 crosswalk: 0
- outside-union non-indexable rows with display asset: 0
- legacy career_jobs rows outside union: 36

## Previous Candidate Disqualification

`computer-occupations-all-other` is not usable as the replacement now:

- occupation row exists
- observed O*NET-SOC 2019 crosswalk exists
- existing index state rows: 2
- existing indexable state rows: 2
- disqualification: already indexable

The controlled import command correctly failed dry-run rather than writing authority rows.

## Rejected Candidate Classes

### CN directory/proxy rows

The 1543 outside-union non-indexable rows are not acceptable replacement authority because they are CN directory/proxy-style rows. They must not be counted toward the product-visible 1048 claim.

### Legacy career_jobs rows

The 36 legacy `career_jobs` rows outside the union are not acceptable replacement authority because the 1048 cohort is based on occupation foundation authority plus runtime projection gates, not legacy job content rows.

### Already indexable occupation rows

Rows such as `computer-occupations-all-other` fail the replacement gate because the replacement must be non-indexable before controlled import.

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

`DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_REPAIR-01`

Purpose: create or identify a real non-CN, O*NET/SOC-backed, non-indexable occupation authority row with controlled v4.2 display asset readiness before generating a new replacement import package.

Only after that import succeeds should the train return to:

`DETAIL_READY_1048_DELTA_AUTHORITY_REPAIR-01`
