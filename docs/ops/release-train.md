# Release Train Orchestrator (V1)

## What this does
- Adds a dedicated `release-train` GitHub workflow for manifest-driven release execution.
- Adds a structured release manifest and CLI for:
  - manifest validation
  - dry-run
  - plan rendering
  - run execution with fail-closed behavior
  - sidecar classification
- Adds release safety modules for:
  - risk classification
  - scope validation
  - smoke checks
- Adds backend deployment wrappers (verification only in this repository).

## What this does not do
- Does not modify existing `deploy.yml` behavior.
- Does not implement production push-main auto deploy.
- Does not perform real production deploy by default.
- Does not execute rollback by default.
- Does not run Search Channel actions.
- Does not perform URL submission.
- Does not modify `fap-web` in this phase.

## V1 production model
- New workflow is `workflow_dispatch` only.
- Default and safe mode is dry-run.
- `allow_deploy` gates run behavior.
- Production execution depends on GitHub Environment approval on the actual
  deploy-capable `run-train` job (`environment: production`).
- Wrapper execution is fail-closed:
  - Missing/disabled deploy command -> `DEPLOY_COMMAND_NOT_CONFIGURED`.
  - Missing rollback command -> `ROLLBACK_COMMAND_NOT_CONFIGURED`.

## Workflow inputs
- `manifest_path`
- `mode`: `validate`, `plan`, `dry-run`, `run`
- `train_id`
- `allow_merge`
- `allow_deploy`
- `allow_rollback`
- `dry_run`

## Concurrency
- `group: production-release-train`
- `queue: max`
- No `cancel-in-progress` use.

## Environment and approvals
`validate` and `dry-run` jobs do not use a production environment and must not
request production approval. The deploy-capable `run-train` job is the job that
declares `environment: production`, so GitHub production protection rules and
environment-scoped secrets apply to the same job that can invoke
`release_train.py run`.

`run-train` is guarded by all of the following workflow inputs:

- workflow ref is `refs/heads/main`
- `mode == run`
- `allow_deploy == true`
- `dry_run != true`

This keeps dry-run validation outside production approval while making the
future real backend run path wait for the production environment reviewer before
the job can access production environment secrets.

The main-branch guard is enforced in the repository workflow, not only by
external GitHub Environment settings. A manual dispatch against any non-main ref
must not enter the deploy-capable `run-train` job even if the dispatcher supplies
deployment-oriented inputs.

The workflow passes operator inputs to shell steps through environment variables
and validates the manifest path before use. The manifest path must be a relative
JSON file that exists in the checked-out repository, must not be absolute, and
must not contain `..` path components.

## Manifest
Top-level fields:
- `schema_version`
- `train_id`
- `environment`
- `mode`
- `stop_on_failure`
- `rollback_on_failed_smoke`
- `allow_merge`
- `allow_deploy`
- `allow_rollback`
- `items`

Each item includes:
- `repo` (`fap-api` or `fap-web`)
- `pr_number`
- `expected_head_sha`
- `expected_merge_sha` (optional)
- `component`
- `risk_level`
- `deploy_required`
- `deploy_order`
- `required_checks_policy`
- `allowed_files`
- `allowed_generated_paths`
- `scope_validation`
- `smoke_checks`
- `rollback`
- `sidecar_policy`

Manifest items must not provide deployer override fields. The release train does
not accept `deployer_bin`, `deployer_file`, `DEPLOYER_BIN`, or `DEPLOYER_FILE`
from a manifest, and the run path blocks any item that contains those fields.
The deploy wrapper is responsible for resolving the approved Deployer binary and
deploy file from repository-controlled configuration.

## Smoke checks
`smoke.py` supports:
- method/url/timeout/retries
- status expectation
- must contain / must not contain
- optional forbidden marker scan for high-risk URLs
- optional soft-alert metadata for non-core discoverability artifacts:
  - `surface: llms-full`
  - `soft_alert: true`
  - `hard_block: false`
  - `core_smoke: false`
  - `owner`
  - `recommended_followup`

## Sidecar policy
- Required check failures are blocking.
- Non-required checks can be sidecar only when failure is explicitly external.
- `5xx`, `timeout`, private URL exposure, held slug exposure, clinical/depression exposure, core smoke failures, Search Channel checks, and staging containment checks are hard-blocking by default.
- `llms-full` and equivalent GEO/discoverability artifacts may be downgraded to sidecar only when all are true:
  - the smoke check explicitly sets `soft_alert: true`, `hard_block: false`, and `core_smoke: false`
  - the item `sidecar_policy.allow_discoverability_artifact_soft_alerts` is true
  - the failure is not a private/held URL exposure, Search Channel anomaly, staging containment regression, or core route/API failure
  - a follow-up owner and recommendation are recorded
- Soft-alert sidecars are not a pass for the artifact itself; they only prevent non-core artifact instability from automatically rolling back or stopping unrelated production release flow.

## Risk classifier
High-risk paths require manual approval and are blocked by default:
- `deploy.php`, `.github/workflows/deploy.yml`
- `backend/scripts/deploy/**`
- queue/scheduler deploy tooling paths
- database/auth/order/payment/Search Channel/URL submission/clinical/depression/software-developers/raw career paths
- backend config, CMS controller/service, SEO/search service, content package,
  and secret-looking paths in the production deployment workflow

Production deployment has no `push`, `pull_request`, or `workflow_run` trigger.
It is available only through manual `workflow_dispatch` after exact-SHA approval,
successful staging evidence for an immutable candidate SHA, a safe release ID,
and the exact bounded SHA/release approval phrase. The candidate may trail
`main` without expiring when it remains an ancestor of current `main` and the
staging run is for that exact candidate. Newer main commits are intentionally
excluded rather than silently added to the deployment.

After production credentials are loaded but before any remote mutation, the
workflow re-fetches `main`, reads the current production `REVISION`, and proves
both ancestry edges:

```text
current production REVISION -> staged candidate SHA -> current main SHA
```

The workflow fails closed on a diverged or unresolvable revision, a rollback,
an already-deployed candidate, or non-exact staging evidence for a candidate
that trails `main`. This allows parallel PR trains to continue without turning
production authorization into a moving target. It does not deploy or authorize
newer main commits.

One exact historical production baseline is separately audited because the
isolated Runtime 46 commit is not topologically present on `main`, while its
six resulting files are already present byte-for-byte in an audited bridge:

```text
production baseline: bc0ed833bc9aae1473ab37f1dead2517e1aff618
audited bridge:      49038deb50cda789e4365ea42068832ed28d6023
```

The workflow accepts only a main-reachable candidate descended from that bridge
and backed by its own exact successful staging run. It then proves that the
production commit has exactly one parent, that parent is an ancestor of the
candidate, the production patch has exactly the locked five additions and one
modification, and all six production blobs equal the corresponding candidate
blobs. Unknown paths, missing files, changed statuses, renames, deletions, blob
drift, another production SHA, or a candidate outside the audited bridge history
fail closed. The release record reports `runtime46_patch_subsumed`; all ordinary
standard deployments continue to report and require `linear_ancestor`.

Standard staging and production deploys run Career public-authority warming as
a bounded derived-cache step. The warm can spend several minutes inside one
dataset phase without application output, so Deployer wraps the exact child
with a fixed `career_warm_heartbeat=running` line every 20 seconds. The wrapper
records the child's real exit status, terminates that exact child on
HUP/INT/TERM, and never prints its PID, routing metadata, target identity,
exception text, or cache keys. Non-strict mode may continue after the bounded
child fails; strict mode preserves the same heartbeat but remains fail closed.
The heartbeat does not retry the warm and does not change CMS, database
authority, publication, sitemap, llms, search, or candidate activation.

Production queue convergence resolves each configured Supervisor entry as an
exact process group (`<program>:*`) or, only when that group is absent, as an
exact single process. The versioned
`backend/scripts/deploy/restart_supervisor_program_group.sh` helper retries a
transient inventory/restart race at most three times with a two-second delay,
requires every resolved process to return to `RUNNING`, and never falls back
from a known group to an unverified bare name. Required groups fail closed;
missing or failed optional groups remain non-blocking. The helper emits only
the configured program label, bounded attempt count, and pass/fail category;
it does not print Supervisor output, PIDs, commands, paths, hosts, or routing
metadata.

The exact bridge candidate `49038deb50cda789e4365ea42068832ed28d6023`
predates the bounded, non-blocking sitemap-source warm helper. When that exact
candidate and staging run `29977064260` are selected, the production workflow
may load a runner-only control wrapper from the immutable workflow-dispatch
SHA. The wrapper first verifies the candidate recipe, wrapper, and helper
SHA-256 values, then loads the candidate's own `deploy.php`, replaces only the
sitemap warm task, and inserts the sitemap-source fallback check before the
existing public-DNS smoke. The helper is streamed over stdin; neither it nor the
wrapper is copied into the remote release. The release checkout, tree, and
`REVISION` remain the exact candidate. The workflow uploads a sanitized
`backend-immutable-candidate-control-receipt.v1`; any identity, hash, staging,
tree, or control drift fails before remote mutation. No other candidate may use
this exception.

Inactive candidate materialization uses the protected production workflow's
`candidate_only` mode. A non-fast-path candidate does not have to equal the
moving tip of `origin/main`; it must be the exact SHA from a successful staging
run and remain an ancestor of both the workflow control-plane SHA and the
write-time `origin/main`. The current active revision must remain an ancestor of
that candidate and must match the remote `REVISION` immediately before any
write. Its exact authorization phrase is:

```text
I explicitly approve bounded backend inactive candidate materialization for exact SHA <CANDIDATE_SHA> using exact staging run <STAGING_RUN_ID> from active SHA <ACTIVE_SHA>, excluding all newer main commits, release <RELEASE_ID>; distinct inactive release path, zero activation.
```

The workflow refetches `origin/main` after entering the protected production
job, revalidates every bound identity and ancestry edge, and fails closed on
drift. It may only run `deploy:candidate-only`; it may not activate a symlink,
deploy the application, migrate, mutate CMS/database authority, warm public
caches, dispatch/restart queues, publish, or change sitemap, llms, search, or
PR23 state. Its sanitized
`backend.inactive_candidate_materialization.v2` receipt binds the actual
control-plane workflow SHA, candidate SHA, exact staging run, current active
revision, inactive release, and write-time main SHA while recording that newer
main commits were excluded. The exact-active same-SHA fast path remains a
separate staging-waived exception with its existing exact authorization phrase.

Any cache-only repair against the inactive candidate must repeat this exact
production/bridge/path/status/blob proof before accepting the otherwise
non-ancestral active revision; it may not weaken the proof to a SHA allowlist.
The Career detail repair workflow checks out the exact current `main` control
plane, hashes the versioned
`backend/scripts/deploy/career_candidate_exact_cache_bootstrap.php` runner, and
streams that runner over stdin. The runner loads only the inactive release's
own `vendor/autoload.php` and `bootstrap/app.php`, then resolves that
candidate's coverage and cache services. It never loads the active release,
dispatches a queue job, uses Supervisor, or stores a repair cursor.

`verify_only` is strictly read-only: it rejects an approval phrase, installs a
fail-closed database guard, validates the exact 2,092-row coverage boundary,
and emits a v2 authorization artifact bound to the workflow run id/attempt,
control-plane SHA, runner SHA256, release identity, coverage fingerprint,
counts, a SHA-bound 2,092-character redacted classification state, 5,000ms
offline budget, one retry, 10-row batch size, and zero-write evidence.
`bootstrap_and_verify` must download that exact successful immutable artifact,
reject v1 packets, and prove there was no intervening bootstrap run. Before any
cache write, the streamed runner combines the authorized classification state
with the current ordered target identities and must reconstruct the exact
operator-authorized coverage fingerprint.

Each 10-row batch runs as the application runtime user with a 720-second
limit. Because every canonical slug has two locale rows, this bounds one
high-density conversion-closure precompute to at most five slugs. The
candidate precomputes conversion closure with one events read, one shortlist
aggregation, and one feedback aggregation per batch, then calls its own
offline synchronous detail warmer only for rows still marked repairable.
After a batch owns one or more cache writes, the workflow waits two seconds
before starting the next candidate process so high-density batches cannot
immediately stack runtime initialization pressure.
The public HTTP warmer retains its separate 2,000ms budget. Only
`build_budget_exceeded` and transient database reads receive one bounded retry
after 500ms; permanent database, cache publish, payload, and unexpected
per-target build errors stop immediately. This same rule wraps the candidate's
full coverage read before each batch: an explicitly classified transient
database read gets one 500ms retry, while a permanent or unclassified failure
stops before that batch owns any write. Pre-batch failures retain the safe
batch offset, stage, category, attempt count, and retry count.
Candidate-runtime initialization is split into application load, kernel
bootstrap, service validation, database guard installation, and service
resolution. If one of those stages reports an
explicit transient database read with zero writes, the workflow preserves the
sanitized first-attempt receipt, revalidates exact active/candidate/lock
identity, waits two seconds, and starts exactly one fresh PHP process. The same
single fresh-process recovery is allowed for the top-level sanitized
`UNEXPECTED_RUNNER_FAILURE` only when it reports
`initialize_candidate_runtime`, zero cache/queue/database writes, and no
target, slug, locale, message, or cache key. A second failure, a permanent
classified failure, or any failure outside that exact zero-write boundary
stops immediately. The workflow never reuses a partially initialized Laravel
process. Receipts contain only safe
failure stage/category, build timings, row-index hash, and pre/post coverage
fingerprints. They never contain target identity, query text, exception text,
cache keys, or SSH routing data.

When an operator does not know the exact production release inputs,
`Backend Production Release Discovery` is the controlled read-only authority.
It runs only from exact latest `main` under the production deployment concurrency
group, refuses an active deploy lock, resolves the managed `current` symlink, and
reads at most 50 managed release `REVISION` files. On the runner it binds each
inactive release to `origin/main` ancestry and an exact successful
`Deploy Application` staging run. Its artifact contains only release names,
commit SHAs, staging run IDs/attempts, eligibility booleans, and zero-write
attestations. It never deploys, activates, warms caches, dispatches queues,
migrates, mutates CMS/database authority, writes remote files, restarts
processes, reads raw logs, or submits search URLs.

A successful batch must read back every target written by that batch as
covered. Because the derived Career cache is shared with the live read-through
warmer, targets may independently move from `missing_pointer` to a covered
state while authorization is pending, between batches, or while a batch is
running. The runner permits only that safe monotonic transition after proving
the original fingerprint; target identity changes, covered-class changes,
regressions, or any other classification transition fail closed. Safe
monotonic gain is reported separately as
`concurrent_coverage_gain_count`; the runner-owned count remains
`owned_cache_write_count` and continues to equal `cache_write_count`.
Broken/invalid or excluded rows, owned targets that remain missing, and
coverage report inconsistencies also fail closed. The workflow feeds the
observed post-batch missing count, coverage fingerprint, and redacted state
into the next batch rather than inferring them from the runner-owned write
count.

A failed run preserves verified cache. Recovery starts again at offset zero
after a new read-only preflight and new exact authorization; already-ready rows
are automatically skipped. Final readback must prove `2092/2092`, zero
missing/broken/excluded rows, runner-owned plus concurrent monotonic coverage
gain equal to the exact authorized initial missing count, zero queue dispatches
and database writes, the unchanged active SHA, and the still-inactive
candidate. The retired
`88dedb58f341e6c92d07754eac7862fa3454dc7c` candidate is permanently rejected.
This workflow never deploys, activates, migrates, publishes, writes CMS/database
authority, changes indexability, or touches sitemap, llms, or Search Channel
state. Its production routing metadata is consumed only from environment
secrets; there is no Actions-variable fallback.

The exact write authorization format is:

```text
I explicitly approve production Career inactive-candidate exact cache bootstrap with authorization preflight run <PREFLIGHT_RUN_ID> coverage fingerprint <COVERAGE_SHA256> control-plane SHA <CONTROL_SHA> runner SHA256 <RUNNER_SHA256> active SHA <ACTIVE_SHA> using exact staging run <STAGING_RUN> and inactive candidate SHA <CANDIDATE_SHA> release <RELEASE> for exactly <MISSING> missing pointers across 2092 targets with offline build budget 5000ms, retry limit 1, batch size 10 and dense-batch cooldown 2s; candidate-code synchronous cache-only batches, no active default worker/queue/CMS/DB-authority/publication/indexability/sitemap/llms/search/candidate activation.
```

## fap-web handling in V1
- `fap-web` is a reference only.
- No production write operation is implemented for `fap-web` in this phase.
- frontend deployment actions remain interface placeholders or future extension.

## Wrapper behavior
- `backend/scripts/deploy/deploy_backend.sh`
- `backend/scripts/deploy/rollback_backend.sh`
- `backend/scripts/deploy/readiness.sh`
- `backend/scripts/deploy/post_deploy_validate.sh`

If wrappers are invoked without explicit runtime intent flags they fail closed.

`readiness.sh` and `post_deploy_validate.sh` use separate evidence surfaces and
fail closed unless their required values are explicit:

- `HEALTHCHECK_HOST=<target-node HTTPS vhost hostname>`
- `PUBLIC_API_BASE_URL=<public API HTTPS origin>`
- `PUBLIC_WEB_BASE_URL=<public Web HTTPS origin>` (`post_deploy_validate.sh`)
- `BACKEND_SHA=<40-character release SHA>`
- `RELEASE_NAME=<operator-approved release name>`
- `PROBE_ID=<unique non-sensitive log correlation id>`

The internal health check must run on the target node and resolves the named
vhost to loopback for `GET /api/healthz`. It requires HTTP `200` and JSON
`ok=true`. The scripts never print the internal hostname, loopback mapping, or
raw configuration values. A non-allowlisted public request to `/api/healthz`
continues to return `404` by design and is not a wrapper failure signal.

The protected standard production workflow and Deployer enforce the same split
before and after release activation. The target node's loopback-resolved vhost
must return `200` with `ok=true`; the public health endpoint must return exactly
`404`; and public-DNS business evidence must include successful flags and
zh-CN Big Five Hub Personality API responses. The Personality response must
contain a valid 64-character `source_hash`. Public `/api/healthz` is never used
as a `200` readiness requirement.

The post-symlink public scale lookup smoke uses
`backend/scripts/deploy/verify_scale_lookup.sh`. Each configured slug receives
at most three complete HTTP-and-JSON attempts, with a two-second delay, a
five-second connect timeout, and a forty-second per-attempt timeout. A transient
transport error or `5xx` may recover on a later attempt; persistent transport
failure, malformed JSON, `ok != true`, or a mismatched `primary_slug` remains a
blocking deployment failure. The helper does not print the probed authority or
the response body.

`backend-production-verify-only.yml` is a separate, manual, exact-SHA production
evidence workflow. It runs only from `main`, requires the exact read-only
approval phrase, and verifies the deployed `REVISION`, release directory,
internal health, public health policy, business APIs, content source hash,
scale lookups, RIASEC question counts, schema, web processes, and all discovered
`fap-queue` processes. It creates only a sanitized runner-side artifact retained
for 30 days. It does not deploy, migrate, publish, restart or unlock processes,
write remote files, inspect raw logs, submit search URLs, or change CMS/database
state. A failed verification stops with evidence only and does not repair the
production node. Release directory names may contain ASCII letters in either
case, digits, dots, underscores, and hyphens, up to 128 characters; this includes
the uppercase `T` and `Z` used by existing UTC-stamped production releases.

Public backend evidence comes from `GET /api/v0.3/flags` and the zh-CN Big Five
hub Personality API. Both must return `200`. The Personality request carries
`User-Agent: FermatMindReleaseProbe/<PROBE_ID>` so a later read-only target-node
check can prove that the public request reached the inspected node. Acceptance
also requires the target node's current release SHA to equal `BACKEND_SHA`;
these wrappers validate the supplied evidence contract but do not inspect or
change the current symlink themselves.

`deploy_backend.sh` is connected to the existing backend Deployer contract and
does not accept an arbitrary `DEPLOY_COMMAND`. The guarded command shape is:

```bash
vendor/bin/dep deploy production -f deploy.php -o release_name="$RELEASE_NAME" --no-interaction
```

Required real-deploy environment:

- `DEPLOY_DRY_RUN=false`
- `ALLOW_PRODUCTION_DEPLOY=true`
- `ALLOW_REAL_DEPLOY=true`
- `DEPLOY_ENV=production`
- `BACKEND_DEPLOY_SHA=<current checked-out backend sha>`
- `RELEASE_NAME=<operator-approved release name>`

Safe dry-run example:

```bash
DEPLOY_DRY_RUN=true \
ALLOW_PRODUCTION_DEPLOY=true \
ALLOW_REAL_DEPLOY=false \
DEPLOY_ENV=production \
BACKEND_DEPLOY_SHA="$(git rev-parse HEAD)" \
RELEASE_NAME="adapter-dry-run-test" \
bash backend/scripts/deploy/deploy_backend.sh
```

The dry-run validates inputs and prints the planned command, but it does not
execute Deployer, rollback, frontend deployment, migrations, Search Channel, or
URL submission.

## Recovery guidance
- If deploy wrapper outputs `REAL_DEPLOY_NOT_ALLOWED`, set release-time environment:
  - `ALLOW_REAL_DEPLOY=true`
- If deploy wrapper outputs `PRODUCTION_DEPLOY_NOT_ALLOWED`, set release-time environment:
  - `ALLOW_PRODUCTION_DEPLOY=true`
- If deploy wrapper outputs `BACKEND_DEPLOY_SHA_MISMATCH`, sync the checkout to
  the approved SHA before retrying.
- If rollback wrapper outputs `ROLLBACK_COMMAND_NOT_CONFIGURED`, set release-time environment:
  - `ALLOW_REAL_ROLLBACK=true`
  - `ROLLBACK_COMMAND=<existing rollback command>`

## Required readiness mapping (future)
- Existing process readiness (`local-readiness` and manual checks) still applies before enabling `allow_deploy=true`.
- The previous manual confirmation phrases remain required before any real deploy action.

## CLI
- `validate-manifest`
- `plan`
- `dry-run`
- `run`
- `resume`
- `print-confirmation-phrases`

## Recovery for v1 blockers
- If production deployment command is unknown, run remains blocked and should be handled by manual platform owner with explicit wrapper configuration.
- If high-risk path mismatches appear, update manifest scope or narrow file impact.
