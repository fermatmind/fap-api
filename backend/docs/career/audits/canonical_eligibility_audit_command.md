# Career Canonical Eligibility Audit Command

AUDIT-9 wires the AUDIT-1 schema and AUDIT-2 through AUDIT-8 audit layers into a read-only artisan command:

```bash
php artisan career:audit-canonical-eligibility --scope=slugs --slugs=actuaries --locales=en --json
```

The command is an integration shell for the canonical eligibility stack. It does not apply rollout, backfill data, mutate DB state, fetch production HTML, deploy, or claim 2786 readiness.

## Options

- `--scope=all|batch|slugs`
- `--slugs=`
- `--locales=`
- `--public-resolution-plan=`
- `--json`
- `--output=`
- `--include-surfaces`
- `--include-live-html`
- `--base-url=`

`scope=slugs` can run from explicit slugs. `scope=all` and `scope=batch` require a public-resolution plan path and report a structured `public_resolution_plan_missing` reason when it is absent.

## Read-Only Contract

Every JSON payload includes:

```json
{
  "read_only": true,
  "writes_database": false,
  "audit_command": "career:audit-canonical-eligibility"
}
```

AUDIT-9 does not write DB rows. `--output` may write the JSON artifact to a caller-specified local file.

## Context Handling

The command separates real blockers from missing verifier context. Layers without supplied artifacts are marked `unverified` with `validator_context_missing`. Optional live HTML validation requires `--include-live-html` and `--base-url`; otherwise the surface layer remains unverified instead of passing.

## Non-Goals

AUDIT-9 does not:

- run rollout apply/backfill/rollback/quarantine
- publish new occupations
- run production DB queries
- deploy
- fetch live production HTML
- generate 80/300/800/2786 manifests
- claim full 2786 readiness

AUDIT-10 should consume this command output to build the 80-cohort readiness plan.
