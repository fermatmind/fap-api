# TEST-KPI-POST-DEPLOY-SMOKE-07

Date: 2026-06-13
Mode: production read-only smoke.

Scope constraints honored:

- No code changes.
- No CMS mutation.
- No deployment.
- No database mutation.
- No route, sitemap, llms, schema, header, or footer changes.
- No generated artifacts committed.

## Decision

`blocked_authenticated_ops_ui_visibility_not_verified`

The implementation train is merged into `main` and source/local scheduler evidence is present. Public production frontend and API probes are reachable. The remaining blocker is live authenticated Ops verification: the available browser context is not logged in and redirects `https://ops.fermatmind.com/ops` to `/ops/login`, so the current production Ops KPI cards and daily detail page could not be visually verified from an authenticated session in this run.

## Merged PR Evidence

Backend PRs verified as `MERGED` by GitHub and verified as ancestors of `fap-api` `origin/main`:

| PR | Title | Merge commit | Merged at |
| --- | --- | --- | --- |
| #2064 | `TEST-KPI-READMODEL-01: Add test metrics daily read model` | `c882a0ab8d389cebf610a18726d6a45f7295aa18` | `2026-06-13T11:49:51Z` |
| #2065 | `TEST-KPI-REFRESH-COMMAND-02: Add test metrics refresh command` | `3d4dfd1560a76fe66899efde097a1c0f08a1fe44` | `2026-06-13T12:07:55Z` |
| #2068 | `TEST-KPI-OPS-SUMMARY-03: Add Ops test KPI summary cards` | `61503abb4a34958ac0d4d5a5f0ff7cef991bf4b0` | `2026-06-13T12:48:25Z` |
| #2070 | `TEST-KPI-OPS-DAILY-BY-TEST-04: Add Ops daily by-test KPI detail` | `d4ea35046d1f8369ba77e94d12a29152f8ba1c1b` | `2026-06-13T13:10:11Z` |
| #2072 | `TEST-KPI-SCHEDULER-05: Schedule test KPI current-day refresh` | `42279b52b1f10fa5ace9253c29af28ca14b36288` | `2026-06-13T13:32:02Z` |

Frontend PRs verified as `MERGED` by GitHub and verified as ancestors of `fap-web` `origin/main`:

| PR | Title | Merge commit | Merged at |
| --- | --- | --- | --- |
| #1131 | `TEST-KPI-FRONTEND-CONTRACT-06: guard frontend test KPI metadata` | `fe92c54e47529d0a7cfe9df2b9c66d98c6ac3643` | `2026-06-13T14:03:43Z` |
| #1132 | `Fix scope guard post-merge contract validation` | `41c5fbd21e9c95fa2ec4cd6ae24d8f65717d0260` | `2026-06-13T14:14:58Z` |

## Source Evidence

`analytics_test_metrics_daily` exists as the backend-authoritative read model with:

- dimensions: `day`, `org_id`, `scale_code`, `scale_code_v2`, `scale_uid`, `form_code`, `locale`
- measures: `started_attempts`, `successful_attempts`, `failed_attempts`, `total_attempts`
- unique key: `day`, `org_id`, `scale_code`, `scale_code_v2`, `form_code`, `locale`

`analytics:refresh-test-metrics-daily` supports:

- explicit `--from` / `--to`
- optional `--scale` and `--org`
- safe `--dry-run`
- guarded writes with exact `--confirm-write`
- scheduler-only `--scheduled-current-day`

Ops summary source reads `analytics_test_metrics_daily` for:

- today's successful attempts
- today's failed attempts
- cumulative successful attempts
- cumulative failed attempts

Ops daily detail source provides:

- date range filters
- current org and global `org_id=0` scope
- scale, form, and locale filters
- daily rows grouped by `day`, `scale_code`, `scale_code_v2`, `form_code`, and `locale`
- success, failure, total, and rate fields

Scheduler source registers:

```text
php artisan analytics:refresh-test-metrics-daily --scheduled-current-day
```

with `everyFifteenMinutes()` and `withoutOverlapping(20)`.

Local `php artisan schedule:list --no-ansi` showed the same command as:

```text
*/15 * * * *  php artisan analytics:refresh-test-metrics-daily --scheduled-current-day
```

## Production Read-Only Probes

| Probe | Result | Evidence |
| --- | --- | --- |
| `https://fermatmind.com/zh/tests` | Pass | HTTP 200; page contains MBTI, Big Five, RIASEC/Holland, Enneagram, IQ, and EQ cards. |
| `https://fermatmind.com/zh/tests/big-five-personality-test-ocean-model/take` | Pass | HTTP 200 final response; `X-Robots-Tag: noindex, nofollow, noarchive, nocache`; `Cache-Control: private, no-store, max-age=0, must-revalidate`. |
| Production frontend JS chunks from `/zh/tests` and Big Five take page | Pass | 18 chunks downloaded for inspection; production bundle contains observable funnel events including `start_attempt` and `submit_attempt`, plus metadata fields such as `scale_code`, `scaleCode`, `form_code`, and `locale`. |
| `https://api.fermatmind.com/api/v0.3/scales?locale=zh-CN` | Pass | HTTP 200; response includes public scale catalog data including `BIG5_OCEAN`. |
| `https://api.fermatmind.com/api/healthz` | Informational | HTTP 404 JSON. No public deploy identifier available from this endpoint. |
| `https://api.fermatmind.com/healthz` | Informational | HTTP 404. No public deploy identifier available from this endpoint. |
| `https://ops.fermatmind.com/ops` via curl | Inconclusive | Local curl returned TLS `SSL_ERROR_SYSCALL`. |
| `https://ops.fermatmind.com/ops` via MCP browser | Blocked | Browser reached the site but redirected to `https://ops.fermatmind.com/ops/login`; current MCP browser context has no authenticated Ops session. |

## Not Verified In This Run

- Authenticated production Ops homepage cards:
  - today's successful tests
  - today's failed tests
  - cumulative successful tests
  - cumulative failed tests
- Authenticated production Ops daily by-test page:
  - date filter
  - test/scale filter
  - form filter
  - locale filter
  - success/failure/total rows
- Server-side scheduler runner on production:
  - `crontab -l`
  - `schedule:run` or supervised `schedule:work`
  - supervisor status for the current release

These require either a logged-in Ops browser session or server read access. They were not inferred from source.

## Local Validation

Commands run:

```bash
git fetch origin main --prune
git merge-base --is-ancestor c882a0ab8d389cebf610a18726d6a45f7295aa18 origin/main
git merge-base --is-ancestor 3d4dfd1560a76fe66899efde097a1c0f08a1fe44 origin/main
git merge-base --is-ancestor 61503abb4a34958ac0d4d5a5f0ff7cef991bf4b0 origin/main
git merge-base --is-ancestor d4ea35046d1f8369ba77e94d12a29152f8ba1c1b origin/main
git merge-base --is-ancestor 42279b52b1f10fa5ace9253c29af28ca14b36288 origin/main
git -C /Users/rainie/Desktop/GitHub/fap-web merge-base --is-ancestor fe92c54e47529d0a7cfe9df2b9c66d98c6ac3643 origin/main
git -C /Users/rainie/Desktop/GitHub/fap-web merge-base --is-ancestor 41c5fbd21e9c95fa2ec4cd6ae24d8f65717d0260 origin/main
curl -sS -D /tmp/test-kpi-zh-tests.headers https://fermatmind.com/zh/tests -o /tmp/test-kpi-zh-tests.html
curl -sS -L -D /tmp/test-kpi-bigfive-take.headers https://fermatmind.com/zh/tests/big-five-personality-test-ocean-model/take -o /tmp/test-kpi-bigfive-take.html
curl -sS -D /tmp/test-kpi-api-v03-scales.headers 'https://api.fermatmind.com/api/v0.3/scales?locale=zh-CN' -o /tmp/test-kpi-api-v03-scales.json
cd backend && php artisan schedule:list --no-ansi | rg -n "analytics:refresh-test-metrics-daily|Next Due|Command" -C 2
```

Final PR validation is recorded in the PR and includes focused KPI tests, markdown diff check, and scope validation.
