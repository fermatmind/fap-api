# GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-PUBLISH-02

## Executive Summary

The exact approval phrase was present, the deployed backend revision matched `76a326807b38b43c807d6fb6348c2684ea5e8aae`, and production exposed the official `content-pages:publish-controlled` runtime.

The production dry-run failed closed before publish. No CMS records were published. The runtime blocked the `foundation` draft with `foundation_overclaim_detected`, so the execute command was not run.

## Approval Verification

- Exact approval phrase present: yes
- Approved target pages: `brand`, `charter`, `foundation`, `careers`, `policies`
- Approved mode: dry-run first, publish only the five existing English CMS draft records, no deploy, no Search Channel, no URL submission

## Runtime / Revision Verification

- Production backend revision: `76a326807b38b43c807d6fb6348c2684ea5e8aae`
- Release: `prod-20260527-76a32680`
- Runtime command verified: `content-pages:publish-controlled`

## Target Pages

The five target CMS records exist and remain:

- `status=draft`
- `is_public=false`
- `is_indexable=false`
- `published_at=null`
- `source_doc=global-en-zh-content-pages-cms-draft-update-01 from human revision packages`

## Preflight Result

Preflight passed until the official runtime claim guard evaluated the foundation draft during dry-run.

The foundation page retained the expected `planned_public_benefit_shareholding` fact state and `Public-Benefit Mission and Governance` title.

## Dry-run Result

Command:

```bash
php artisan content-pages:publish-controlled \
  --locale=en \
  --keys=brand,charter,foundation,careers,policies \
  --dry-run \
  --json
```

Result:

- `ok=false`
- `dry_run=true`
- `writes_committed=false`
- `target_count=5`
- `would_create_count=0`
- `blocked_count=1`
- Error: `foundation_overclaim_detected`
- Search Channel action attempted: false
- URL submission attempted: false
- External search API attempted: false
- Deploy attempted: false

## Controlled Publish Result

No execute command was run because dry-run failed.

Published pages: none.

## Foundation Governance Boundary

The blocker is inside the foundation page claim guard. The runtime matched the phrases:

- `formal board governance`
- `completed equity transfer`

Those phrases appeared in a negative boundary sentence saying the page does not claim those items. The deployed runtime is intentionally conservative and still blocks exact forbidden phrases.

## Public Runtime Verification

Because no publish occurred, the five pages remain non-public:

- `/en/brand`: 404, `noindex`
- `/en/charter`: 404, `noindex`
- `/en/foundation`: 404, `noindex`
- `/en/careers`: 404, `noindex`
- `/en/policies`: 404, `noindex`

## Sitemap / llms / Footer Exposure Check

No target page appeared in:

- `/sitemap.xml`
- `/llms.txt`
- `/llms-full.txt`
- `/en`
- `/zh`

## Search Channel Safety

- No Search Channel queue item was created for the five content pages.
- Queue item 2 remains EN MBTI approved/submitted.
- Queue item 3 remains ZH MBTI approved/submitted.
- Search Channel gates remain closed.

## Validation

Validation is recorded in the generated JSON and focused test for this blocked publish attempt.

## PR / Merge Result

Pending at report creation.

## Sidecar Issues

The current foundation copy or claim guard needs a follow-up. The safest remediation is either:

- revise the foundation draft so the boundary sentence avoids exact forbidden phrases, or
- update the runtime claim guard to recognize explicit negative boundary language without allowing positive overclaims.

## What Was Not Done

- No publish execute command was run.
- No CMS record was published.
- No deploy was performed.
- No Search Channel enqueue was performed.
- No URL was submitted.
- No external search API was called.
- No sitemap, llms, footer, or nav exposure was enabled.
- No fap-web file was modified.

## Final Decision

`blocked_foundation_fact_overclaim`

## Next Task

`GLOBAL-EN-ZH-CONTENT-PAGES-HUMAN-REVISION-R2`
