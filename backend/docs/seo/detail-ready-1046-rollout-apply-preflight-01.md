# DETAIL_READY_1046_ROLLOUT_APPLY_PREFLIGHT-01

## Executive Summary

Final decision: `blocked_backend_deploy_required`.

The clean `1046` manifest from PR #1747 is merged into `main`, but production backend is still running:

- production backend SHA: `16c01f81b60bb9a5452a867c6c57a78aa77e57bc`
- required SHA: `6716bf50c3cd22691d4609434f8a2b12442b2c8a`

Because production does not include PR #1747, this task does not produce an explicit apply approval phrase.

## Revision / Dependency Verification

- fap-api `main` includes PR #1747 and merge commit `6716bf50c3cd22691d4609434f8a2b12442b2c8a`.
- production backend `REVISION` is `16c01f81b60bb9a5452a867c6c57a78aa77e57bc`.
- `required_sha_present=false`.

## Manifest Verification

- current public detail count: `30`
- clean delta count: `1016`
- target public total: `1046`
- manifest_safe: `true`
- apply_allowed: `false`
- rollout_apply_allowed: `false`

Excluded slugs:

- `software-developers` as manual hold
- `digital-forensics-analysts` as conflict slug
- `computer-occupations-all-other` as already-indexable replacement exclusion

## Runtime Command Discovery

Official command exists:

```bash
php artisan career:execute-canonical-rollout-batch --dry-run --json ...
```

However, source inspection shows this dry-run currently writes a filesystem audit artifact under:

```text
storage/app/private/career_canonical_rollout_batch_executions
```

Because this task forbids production writes, the production dry-run was not executed.

## Production Dry-run Result

Production dry-run was not performed.

Reasons:

- production backend does not include PR #1747
- the existing dry-run command writes an audit artifact file, and this task is no-production-write

## API Safety Pre-check

Observed before apply:

- `/en/career/jobs`: `200`
- `/zh/career/jobs`: `200`
- current public detail count from read-only audit: `30`
- `software-developers`: public pages remain `404/noindex`
- `digital-forensics-analysts`: public pages remain `404/noindex`
- sitemap/llms/llms-full career detail exposure: `0`

## Claim Boundary Pre-check

This preflight did not change runtime copy or claims. The intended rollout scope remains publication/projection state only and must not add best-career, hiring-fit, salary, career-success, or psychometric-determines-career claims.

## Search Channel Safety

No Search Channel enqueue, live submission, external search API call, or URL submission was performed.

## Exact Future Apply Approval Phrase

No approval phrase is produced because preflight is blocked by backend deploy requirement.

## What Was Not Done

No production write, DB mutation, CMS mutation, runtime promotion, deploy, sitemap/llms/footer exposure, Search Channel action, URL submission, external search API call, fap-web change, software-developers manual hold release, digital-forensics use, replacement search, raw log read, or production user data access was performed.

## Next Task

Deploy fap-api PR #1747, then rerun this preflight. If the no-production-write constraint remains, add or verify a no-audit/no-write dry-run mode before executing production dry-run.
