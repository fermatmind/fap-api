# Backend incident and rollback

## Read-only incident sequence

1. Freeze workflow run/attempt, approved SHA, release ID, staging run, and receipt identities.
2. Inspect the workflow incident receipt and job checkpoints.
3. Read active `REVISION`, managed release identity, deploy-lock state, relevant process state, Supervisor status, and public health.
4. Classify the last boundary: `eligibility_failed`, `release_materialized`, `migration_started`, `activation_started`, `activation_committed`, `queue_reload_failed`, or `smoke_failed`.
5. Report the safest next controlled action.

Never expose raw logs, environment values, database credentials, private paths, or topology in public output.

## Separately controlled actions

Require exact action-specific authorization for:

- deploy lock removal;
- process termination or service restart;
- migration or schema repair;
- cache or queue repair;
- rollback;
- CMS/content/database/Redis mutation;
- SSH/sudo permission changes.

## Rollback assessment

Prove before requesting approval:

- current and target release SHA/ID;
- target immutable release exists and was previously healthy;
- migration compatibility and whether rollback needs data action;
- queue worker and Scheduler expectations;
- post-rollback schema, health, scale, and content smoke set.

Use only the protected repository rollback path. Never edit the active symlink or invoke Deployer directly as a substitute.

## Failure policy

- Eligibility or preflight failure: no deployment retry until inputs/control are fixed.
- Transport failure before activation: preserve evidence; fresh approval is required.
- Activation ambiguity: read-only investigation; no automatic retry.
- Migration ambiguity: do not roll back application or data until migration state is proven.
- Smoke failure with healthy revision: diagnose the failing dependency before changing release state.
