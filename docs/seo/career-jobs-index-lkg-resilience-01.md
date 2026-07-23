# CAREER-JOBS-INDEX-LKG-RESILIENCE-01

## Runtime contract

- `GET /api/v0.5/career/jobs?locale=en|zh-CN` reads only an immutable activated job-index version.
- Read order is `active` then `lkg`. The request path never builds the full Career authority bundle.
- If neither pointer resolves to a payload, the endpoint returns HTTP `503`, `error_code=CAREER_JOB_INDEX_NOT_WARM`, and `Retry-After: 60`.
- A successful `200` keeps the existing `career.protocol.job_index.v1` response fields.
- The warm command builds EN and zh-CN under the existing ordered rebuild locks, stages both immutable payloads, and rolls all job-index pointers back if either locale or the linked directory activation fails.

## Read-only preflight evidence

The 2026-07-23 preflight used public HTTPS only and performed no cache, database, CMS, deploy, publish, or Search Channel write.

- Career directory EN: HTTP 200 in about 0.23 seconds.
- Legacy jobs index EN: HTTP 200 in about 20.9 seconds, approximately 1.26 MB.
- Legacy jobs index zh-CN: did not complete inside the bounded 25-second probe.

This evidence replaces the earlier assumption that the endpoint always returned 504. It still demonstrates that synchronous full-index work sits near or beyond common request timeouts.

## Controlled post-merge operation

This PR does not authorize or execute the following commands. Run them only after the separately controlled deployment confirmation for the exact merged `main` SHA.

```bash
cd /srv/fap-api/backend
php artisan career:warm-public-authority-cache --json
curl --fail-with-body --max-time 30 'https://api.fermatmind.com/api/v0.5/career/jobs?locale=en'
curl --fail-with-body --max-time 30 'https://api.fermatmind.com/api/v0.5/career/jobs?locale=zh-CN'
```

The operator readback must verify both responses are HTTP 200, each has exactly 1046 items, and none of these held slugs is present:

- `software-developers`
- `digital-forensics-analysts`
- `computer-occupations-all-other`

Any partial locale activation, 5xx, item-count drift, or held-slug exposure stops the later reviewer-evidence and search-entry tasks.

## Repository rule impact

Career content and public enumeration remain backend-authoritative. This change modifies only cache/read failure behavior for the existing legacy endpoint; it does not change career content, publication, indexability, sitemap, llms, canonical URLs, frontend fallback, or Search Channel state.
