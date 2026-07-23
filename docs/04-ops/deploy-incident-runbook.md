# Deploy Incident Runbook

This runbook defines the decision tree for stuck or ambiguous deployment operations. It is read-only by default and does not authorize rollback, unlock, process killing, or server mutation.

## First response rule

When a deploy appears stuck, do not immediately rerun deployment. First determine whether the remote release actually completed, whether workers are healthy, and whether the local deploy process is only waiting on a stale worker step.

## Read-only evidence to collect

Collect evidence without mutating local or remote state:

- local deploy command, release name, and target SHA
- current backend symlink and `REVISION`
- queue and supervisor status
- PHP-FPM and nginx status when readable
- Deployer lock status if readable
- recent deploy task name where execution appears stuck
- whether public smoke, internal smoke, or allowlisted smoke passes

Do not print secrets, private key paths, real IP addresses, tokens, passwords, or private topology in incident notes.

## Stuck deploy decision tree

1. If the remote current symlink and `REVISION` match the intended release and backend smoke passes, classify as likely local deploy worker hang.
2. If remote current symlink does not match the intended release, classify as deploy incomplete.
3. If queue workers are not running or are on the wrong release, classify as worker convergence failure.
4. If nginx or PHP-FPM is unhealthy, classify as web runtime failure.
5. If healthz is checked from a non-allowlisted public source and returns `404`, do not classify as healthz failure. Recheck internally or from an allowlisted source.

## Mutation approvals

The following actions require separate explicit approval after read-only evidence is collected:

- rollback
- unlock
- killing local or remote processes
- restarting supervisor, queue workers, PHP-FPM, nginx, or PM2
- rerunning production deploy
- modifying server files or configuration

Approval for one action does not imply approval for another.

## Backend deploy incident classifications

- `complete_and_verified`: intended release is active and smoke passed
- `local_worker_hang`: remote release is active but local Deployer remains blocked
- `deploy_incomplete`: current symlink or `REVISION` does not match intended release
- `worker_convergence_failure`: queue or supervisor failed to converge
- `web_runtime_failure`: PHP-FPM, nginx, or public smoke failed
- `unknown`: evidence is insufficient or conflicting

## Staging queue capability boundary

Staging currently has an explicit no-worker topology. A staging deployment must
check this boundary before acquiring a deploy lock or moving the current
symlink:

- no Supervisor queue manager is configured;
- no legacy queue systemd unit is configured;
- no unmanaged `artisan queue:work` or `artisan horizon` process may be
  running;
- the post-activation queue-depth smoke remains mandatory.

Production continues to require a configured Supervisor or declared systemd
reload path. The staging exception must never disable the production queue
reload requirement.

The staging workflow obtains SSH topology and smoke endpoints only from
protected GitHub Environment secrets. Logs may report boolean outcomes, counts,
task names, run ids, and exact Git revisions, but must not print host
addresses, remote users, deploy roots, endpoint URLs, SSH fingerprints, lock
metadata payloads, or raw process command lines.

The July 23 staging incident reached the intended exact revision and reloaded
the web runtime before failing on a nonexistent queue-manager fallback. The
owned deploy lock was removed. Read-only follow-up found zero Laravel queue
workers and healthy PHP-FPM/nginx processes. An external `/api/healthz` request
returned the expected restricted-source `404`; per this runbook, that result
must be rechecked through the allowlisted deployment health path and is not by
itself proof of a broken application health route.

### Repository rule impact

This is a deployment-control change only. It does not change CMS authority,
public API contracts, content, database state, queue configuration, or
production deployment authorization.

## Sitemap source cache warm timeout

The sitemap source cache is a derived cache, not publication authority or
release evidence. Standard deployment runs one bounded warm attempt as
`www-data`. A timeout, lock contention, malformed result, empty result, or
command failure is non-blocking by default and must emit only a sanitized
degraded status. The deploy must not retry this heavy generator automatically.

After activation, `healthcheck:sitemap-source` remains fail closed. The target
node loopback response must return `ok=true`, `count>=1`, and either the full
`backend_sitemap_generator` source or the safe
`backend_sitemap_generator_fallback` source. This keeps temporary cache warm
failure separate from public endpoint availability.

Set `DEPLOY_SEO_SITEMAP_SOURCE_WARM_STRICT=true` only for a release that
explicitly requires a successful warm result. Invalid timeout, kill-after,
strict-mode, PHP, or Artisan configuration always fails before execution.
Historical failed workflow runs retain their original conclusion.

The July 23 production run `30008600976` timed out in the pre-activation
sitemap source warm task. The candidate release directory was created, but the
production symlink was not switched. A new deployment requires a new exact
release authorization; the historical run must not be rerun or relabeled.

## Candidate-exact Career dataset cache repair

A failed standard candidate may rebuild the shared Career dataset cache before
`deploy:publish` without changing the production `REVISION`. When a later
code-only release contains the audited publish-track reconciliation input, the
deployment must not treat that stale cache as candidate-equivalent.

The production workflow permits repair only when all of these conditions hold:

- three HTTP/1.1 public reads have the same complete normalized summary hash;
- the live hash is either the exact candidate hash or the one audited stale
  hash produced by the known pre-activation warm;
- the inactive candidate rebuild matches the exact candidate hash;
- the cache key still matches the audited current hash after acquiring the
  deployment-scoped lock;
- the replacement is limited to the Career dataset-hub cache key;
- the public readback exposes the exact candidate hash before activation.

The command stores a one-hour deployment-scoped rollback payload before the
cache replacement. A deployment failure before symlink activation restores
that exact payload before the owned deploy lock is released. Once the candidate
release is the active symlink, a later post-activation hook failure keeps the
candidate-exact cache and finalizes the rollback payload on a best-effort basis.
A failed pre-activation readback rolls back immediately. The operation performs
no CMS/database write and does not alter publication, indexability, sitemap,
llms, or search state.

An unknown hash, unstable three-read sample, candidate mismatch, lock-time
drift, missing rollback payload, or failed rollback verification remains
fail-closed. Do not add another accepted hash without a new read-only incident
audit and a reviewed code change.

## Frontend deploy incident classifications

- `complete_and_verified`: Node1 HEAD matches intended SHA, PM2 converged, public smoke passed
- `pm2_convergence_failure`: PM2 process count or online status is wrong
- `stale_process`: PM2 online but serving an older checkout or build
- `build_or_asset_failure`: local chunk or public static asset smoke failed
- `public_route_failure`: route smoke failed after PM2 convergence
- `unknown`: evidence is insufficient or conflicting

## Sidecar handling during incidents

Do not block a completed and verified deploy on unrelated sidecar issues. Record them separately with owner, severity, and whether they require a follow-up PR or operational task.

Sidecar issue record format:

- summary
- evidence
- scope relation: current deploy / external / historical
- production blocking: yes or no
- recommended follow-up

## Rollback recommendation boundary

Recommend rollback only when the deployed release is active and introduces a production-impacting regression that cannot be mitigated safely within the current release. Missing arbitrary public-origin `/api/healthz` `200` is not by itself a rollback condition because production healthz is allowlist-only.
