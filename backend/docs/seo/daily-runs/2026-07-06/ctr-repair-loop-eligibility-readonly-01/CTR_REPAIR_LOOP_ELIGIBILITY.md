# CTR Repair Loop Eligibility

Date: 2026-07-06
Scope: generated-only eligibility readback

## Eligibility Gate

The CTR repair loop may only run when all gates pass:

| Gate | Required state | Current state | Eligible? |
| --- | --- | --- | --- |
| GSC source engine | `google` | Not proven by live read-model rows | No |
| GSC data origin | `live_gsc_api` | Current generated gate evidence records `fixture` | No |
| Data quality gate | `pass` | `blocked` | No |
| Opportunity queue | `opportunity_queue_eligible=true` | `false` | No |
| Required fields | hash URL, hash query, clicks, impressions | Schema supports fields, passable rows absent | No |
| Raw/private safety | no raw URL/query/credential/private payload | Contract exists; no eligible artifact | No |

## Result

The loop is blocked. No page should be moved into CTR repair from current evidence.

The current train may continue, but downstream CTR/TDK cards must remain dry-run or blocked until a later PR proves live read-model quality.

## What Would Make This Eligible

Minimum future proof:

1. sanitized live GSC artifact or imported read-model rows;
2. `data_origin=live_gsc_api`;
3. `source_engine=google`;
4. finalization lag and freshness pass;
5. required metric fields present;
6. `GscDataQualityGate.status=pass`;
7. `opportunity_queue_eligible=true`.

## Non-Actions

This PR did not:

- call Google Search Console;
- import or write `seo_gsc_daily`;
- select pages for repair;
- write title/meta/H1/FAQ/CTA;
- submit URLs;
- trigger deploy.
