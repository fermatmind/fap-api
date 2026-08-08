# Backend Production Supervisor Read-only Sudo Control

This protected workflow repairs only the non-interactive permission required by
`Backend Production Verify Only` to execute:

```text
/usr/bin/supervisorctl status
```

It does not authorize other `supervisorctl` subcommands, service restart,
Supervisor configuration changes, deployment, migration, application writes,
cache warming, publication, or discoverability changes.

## Preflight

Run `Backend Production Supervisor Read-only Sudo Control` from exact latest
`main` with `mode=preflight`, the exact control-plane SHA, and the exact active
backend revision. Preflight uses only the `production` Environment secrets and
streams a read-only probe without creating a remote file.

The immutable receipt reports only hashes, booleans, bounded counts, and fixed
status identifiers. `PASS_PREFLIGHT_APPLY_ELIGIBLE` is the only status that may
produce an apply authorization phrase. `PASS_PREFLIGHT_ALREADY_AUTHORIZED`
requires no write. `PASS_PREFLIGHT_MANUAL_ROOT_REQUIRED` means the GitHub deploy
identity cannot safely install and validate its own narrowly scoped rule; an
independent root bootstrap path is required.

## Apply

Apply requires the exact successful preflight run/attempt, receipt SHA-256,
deploy-user fingerprint, rule SHA-256, latest control-plane SHA, unchanged
active revision, and the workflow-generated phrase. It rechecks every binding
before creating a candidate, validates the candidate with `visudo`, installs
one root-owned mode `0440` sudoers include, validates the complete sudoers
policy, hashes the exact installed rule, and verifies that all discovered
`fap-queue*` workers are `RUNNING`.

An apply failure is terminal. Do not retry automatically, delete the installed
rule, restart Supervisor, or broaden sudo access. Inspect the immutable failure
receipt and design a separately authorized recovery when necessary.
