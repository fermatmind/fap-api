# Career job detail cache coverage

`career:verify-job-detail-cache-coverage` audits the backend runtime publish
projection authority against the versioned Career detail cache. It does not use
the frontend directory or a historical manifest as authority.

## Read-only verification

Verification is the default and performs no cache write, queue dispatch, CMS/DB
mutation, publication change, or detail bundle build:

```bash
php artisan career:verify-job-detail-cache-coverage --json
php artisan career:verify-job-detail-cache-coverage --verify-only --locales=en,zh-CN --json
```

The command exits successfully only when every eligible target is covered by an
active, last-known-good, or one-release migratable legacy payload. The JSON
contract reports dynamic published slug and locale counts, all cache-state
classifications, bounded examples, and the coverage ratio. The current rollout
baseline is expected to be 1,046 slugs × 2 locales = 2,092 targets, but the
implementation always derives the slug set from runtime publish authority.

## Bounded repair

Repair queues only `missing_pointer`, `missing_payload`, `broken_pointer`, and
`invalid_payload` targets. It never forgets a healthy active/LKG payload and it
does not rebuild details in the command process:

```bash
php artisan career:verify-job-detail-cache-coverage \
  --repair-missing \
  --locales=en,zh-CN \
  --batch-size=250 \
  --resume-key=operator-ticket \
  --json
```

Repeat the same command to advance the stable target cursor. Use `--reset` only
to restart that cursor intentionally. Repair refuses `queue.default=sync` so a
command cannot accidentally perform full detail assembly inline.

Production repair remains a controlled write boundary and additionally requires
`--confirm-production-write`. This repository PR implements and tests the
capability only; it does not authorize or execute a production repair, deployment,
CMS/DB write, or publication change.

## Deployment activation gate

Every Deployer release runs the read-only coverage command before
`deploy:symlink`. Activation requires `status=ready`, a coverage ratio of 1.0,
and at least 2,092 eligible targets. The target floor can be increased with
`DEPLOY_CAREER_DETAIL_MINIMUM_TARGETS`; lowering it is an operator-controlled
release decision and never repairs or republishes content. A failed gate leaves
the current symlink unchanged. It must not be bypassed by warming one sampled
detail URL.

## Runtime SLO and controlled repair

`career:runtime-slo-check` inspects the complete dynamic published slug × EN/ZH
cache-key set on every scheduled run. It reports the coverage contract beside
the existing page, directory, sitemap, and llms probes and emits distinct alerts
for missing targets, broken targets, and an eligible target count below
`CAREER_RUNTIME_SLO_MINIMUM_DETAIL_TARGET_COUNT` (default 2,092). A single
successful detail request is not accepted as coverage evidence.

Bounded scheduled repair is disabled by default. Setting
`CAREER_RUNTIME_SLO_REPAIR_MISSING_ENABLED=true` registers a ten-minute repair
that reuses the existing `runtime-slo` resume cursor, asynchronous queue guard,
production-write confirmation, and missing/broken-only classification. The batch
size defaults to 100 and is capped at 500. Disable the flag to stop new repair
dispatches; already queued jobs follow the normal queue operations policy.

This PR does not set those production environment values, run the schedule,
dispatch repair jobs, warm production caches, wait for staging, or deploy any
release. Production execution still requires the separate exact-SHA deployment
and environment authorization paths.
