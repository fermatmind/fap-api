# Release Summary — 2026-05-18 — backend healthz alignment and frontend Node1 sync

## Scope
This release sequence completed three related production tasks:

1. deployed backend PR `#1459` to production to add the public Laravel healthz alias route;
2. deployed frontend Node1 to current `main` so the production frontend runtime matched the merged repository state;
3. aligned operational docs and runbooks so production healthz verification uses the correct canonical probe path and access policy.

This document records the deployed revisions, validation evidence, the final healthz decision, and the operator-facing runbook outcome.

## Deployed revisions

### Backend
- release name: `20260518183000`
- deployed SHA: `5f2549a9029d79c71e10b8d04c9753fc138deee0`
- deployed PR: `#1459` — `fix(api): add public /healthz alias`

### Frontend
- deployed SHA: `ac40c1efcf9f3d8ace38b6d362c8caa569ed9145`
- deployed PR: `#839` — `docs(career): add phase 5b fu2 taxonomy policy pack`

### Related documentation alignment
- merged docs PR: `#1460` — `docs(ops): align healthz probe runbooks`

## What happened

### Backend runtime outcome
The backend deploy completed successfully and switched production `current` to the new release containing `#1459`.

Post-deploy backend smoke confirmed:
- schema verification passed;
- ops health snapshot passed;
- public content verification passed;
- queue workers reloaded and remained `RUNNING`;
- scale lookup for MBTI, Big Five, Enneagram, RIASEC, IQ, and clinical endpoints remained healthy;
- RIASEC `riasec_60` returned 60 questions and `riasec_140` returned 140 questions;
- required static backend-served assets returned `200`.

### Frontend runtime outcome
Frontend Node1 was behind local `main` by a delta that was no longer docs-only because it included a runtime route under `app/api/content-release/revalidate/route.ts`.

Frontend deploy completed successfully and confirmed:
- Node1 `HEAD` moved to `ac40c1efcf9f3d8ace38b6d362c8caa569ed9145`;
- PM2 rolling reload converged successfully;
- `fap-web` remained `4/4 online` after reload;
- public endpoint probes completed without a deployment blocker.

## Healthz root cause and final decision

### Observed issue
After the earlier backend deploy to `#1458`, public smoke still reported `404` for `/healthz`.

### Root cause
There were two distinct facts:

1. before `#1459`, Laravel only exposed `/api/healthz` and not a top-level `/healthz` alias;
2. after `#1459`, both `/api/healthz` and `/healthz` existed in Laravel, but healthz remained protected by `HealthzAccessControl`, which only allows configured allowlisted IPs in production.

Additionally, the production ingress/path routing did not make `/healthz` a usable public validation path even though the Laravel route existed.

### Final decision
The operational decision is:
- canonical probe path: `/api/healthz`
- production access policy: allowlist-only or internal-source verification
- `/healthz` is not a current production acceptance requirement
- arbitrary public-origin `404` on `/api/healthz` or `/healthz` is not, by itself, a deploy failure

## Validation evidence

### Public validation
The following public checks succeeded:
- `https://fermatmind.com/` -> `200`
- `https://fermatmind.com/en` -> `200`
- `https://fermatmind.com/zh` -> `200` with canonical redirect behavior remaining intact
- `https://www.fermatmind.com/zh/career/jobs` -> `200`
- `https://www.fermatmind.com/llms.txt` -> `200`
- `https://www.fermatmind.com/sitemap.xml` -> `200`
- `https://api.fermatmind.com/api/v0.3/flags` -> `200`

Public healthz checks returned:
- `https://api.fermatmind.com/api/healthz` -> `404`
- `https://api.fermatmind.com/healthz` -> `404`

These `404` responses are consistent with the final allowlist-only production policy.

### Internal validation
Internal backend validation confirmed the canonical probe path is healthy:
- `http://127.0.0.1/api/healthz` -> `200`
- payload included `{"ok":true,...}`

The backend route table on the active release showed both routes exist:
- `GET|HEAD api/healthz`
- `GET|HEAD healthz`

## Runbook outcome
Operational documentation now reflects the actual supported behavior:
- use `/api/healthz` as the canonical probe path;
- validate from an allowlisted source or from inside the app host;
- use `php artisan ops:healthz-snapshot` as a controlled verification path when needed;
- do not treat arbitrary public-origin `200` on `/healthz` as a deployment success criterion.

## Follow-up status
No immediate production blocker remains from this release sequence.

Future follow-up is optional and should be treated as a separate scoped task:
- if the team wants `/healthz` to return `200` for arbitrary public origins, that is not a runbook issue and not a deploy failure; it requires a separate scoped change to ingress/routing and-or healthz access policy.
