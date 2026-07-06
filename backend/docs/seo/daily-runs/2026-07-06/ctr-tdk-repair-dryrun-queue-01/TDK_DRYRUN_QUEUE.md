# CTR/TDK Repair Dry-Run Queue

Date: 2026-07-06
Scope: generated-only dry-run queue decision

## Queue Status

Final queue status: blocked

| Field | Value |
| --- | --- |
| Eligible source rows | 0 |
| Candidate pages selected | 0 |
| Title/meta/H1 changes drafted | 0 |
| CMS writes | 0 |
| Search actions | 0 |
| Reason | `BLOCKED_BY_GSC_DATA_QUALITY` |

## Required Input Not Available

The queue requires all of these:

- `GscDataQualityGate.status=pass`;
- `opportunity_queue_eligible=true`;
- `data_origin=live_gsc_api`;
- `source_engine=google`;
- safe hashed URL/query fields;
- clicks and impressions;
- finalization/freshness pass.

Current evidence does not meet the input contract.

## Safe Interpretation

The correct queue is empty. Producing candidates now would mix future repair scope into a blocked GSC state and risk TDK edits from non-authoritative data.

## Future Queue Rules

When unblocked, each candidate should include:

- page hash or backend URL Truth reference;
- query hash and masked query display;
- clicks, impressions, CTR, average position, date window;
- why CTR repair is plausible;
- owner page;
- proposed dry-run title/meta/H1 direction;
- claim boundary note;
- explicit no-write status.

## Non-Actions

This PR did not:

- select or rank pages;
- write title/meta/H1/FAQ/body copy;
- touch CMS;
- enqueue Search Channel;
- submit URLs;
- deploy.
