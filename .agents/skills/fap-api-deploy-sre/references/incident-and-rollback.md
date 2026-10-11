# Backend incident and rollback

## Read-only incident sequence

1. Freeze workflow run/attempt, approved SHA, release ID, staging run, and receipt identities.
2. Inspect the workflow incident receipt and job checkpoints.
3. Read active `REVISION`, managed release identity, deploy-lock state, relevant process state, Supervisor status, and public health.
4. Classify the last boundary: `eligibility_failed`, `release_materialized`, `migration_started`, `activation_started`, `activation_committed`, `queue_reload_failed`, or `smoke_failed`.
5. Report the safest next controlled action.

Never expose raw logs, environment values, database credentials, private paths, or topology in public output.

## Separately controlled actions

Outside the classifier-selected automatic `deploy.yml` lane, require exact action-specific authorization for:

- deploy lock removal;
- process termination or service restart;
- migration or schema repair;
- cache or queue repair;
- rollback;
- CMS/content/database/Redis mutation;
- SSH/sudo permission changes.

Existing automatic migration/cache/content operations and same-attempt bounded LKG restoration retain the task's continuous authorization; diagnosis does not grant an ad-hoc production write. Manual recovery is reserved for a real incident after applicable automatic restoration failed.

## Rollback assessment

For recovery assessment, prove:

- current and target release SHA/ID;
- target immutable release exists and was previously healthy;
- migration compatibility and whether rollback needs data action;
- queue worker and Scheduler expectations;
- post-rollback schema, health, scale, and content smoke set.

Use only `recovery.yml` for separately controlled manual recovery. Never edit the active symlink or invoke Deployer directly as a substitute; ordinary same-attempt restoration stays owned by `deploy.yml`.

## Failure policy

- Eligibility or preflight failure: no deployment retry until inputs/control are fixed.
- Transport failure before activation: preserve evidence, diagnose within the same scope, and push a new corrective commit into the original automatic chain. Do not rerun the failed SHA or request a new chat approval for that authorized repair.
- Activation ambiguity: read-only investigation; no automatic retry.
- Migration ambiguity: do not roll back application or data until migration state is proven.
- Smoke failure with healthy revision: diagnose the failing dependency before changing release state.
