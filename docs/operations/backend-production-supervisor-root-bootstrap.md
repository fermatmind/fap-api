# Backend Production Supervisor Root Bootstrap

This protected control uses a minimal dual-identity boundary to bootstrap only
the one sudoers rule required by `Backend Production Verify Only`:

```text
<production deploy user> ALL=(root) NOPASSWD: /usr/bin/supervisorctl status
```

The production Environment secret `PRODUCTION_ROOT_BOOTSTRAP_SSH_USER` names
the existing bootstrap actor (`ubuntu`). The existing `PRODUCTION_DEPLOY_USER`
remains the permission target. Both identities use the existing SSH key; the
workflow neither creates nor changes SSH credentials. The bootstrap actor may
run only the fixed root commands, while the deploy identity performs the final
passwordless `supervisorctl status` readback.

## One-time credential

Before preflight, the operator stores the existing bootstrap actor's sudo
password as the production Environment secret
`PRODUCTION_ROOT_BOOTSTRAP_SUDO_PASSWORD`.
Never pass it as a workflow input, copy it from chat, commit it, or include it
in a receipt. Preflight confirms only that the secret is non-empty and
single-line. It does not authenticate with sudo, so `credential_tested=false`
and no remote sudo timestamp is created.

The secret is one-time. After any separately authorized apply attempt, whether
successful or failed, the operator must remove it from the production
Environment. The immutable apply receipt keeps
`secret_retirement_required=true`; the workflow does not possess permission to
delete GitHub secrets.

## Preflight

Run `Backend Production Supervisor Root Bootstrap` from exact latest `main`
with `mode=preflight`. Bind the successful Supervisor sudo-control receipt that
reported `PASS_PREFLIGHT_MANUAL_ROOT_REQUIRED`, the unchanged active revision,
its original control-plane SHA, deploy-user fingerprint, and exact rule SHA256.

The remote probes are strictly read-only. They prove that the configured
bootstrap actor and deploy target each match their SHA-256 fingerprint and can
authenticate with the existing SSH key. They also check active `REVISION`,
deploy lock/process state, absence of the exact sudoers target, the root-owned
and non-writable sudoers directory, and the fixed `install`, `visudo`,
`sha256sum`, and `supervisorctl` binaries. They do not inspect the complete
sudoers policy or use the sudo password. Receipts expose only the two user
fingerprints, never the raw user names.

Only `PASS_ROOT_BOOTSTRAP_PREFLIGHT` with `apply_eligible=true` may produce the
receipt-bound approval phrase. Download the artifact, recompute its digest and
the JSON SHA256, and instantiate the phrase from the exact receipt fields.

## Apply

Apply is a separate production permission write. It requires the exact source
receipt, bootstrap preflight run/attempt/receipt, latest control-plane SHA,
unchanged active revision, deploy-user fingerprint, rule SHA, and a byte-exact
operator approval phrase.

The password travels only over SSH stdin to the bootstrap actor and may feed
exactly three fixed sudo command types: `install`, full-policy `visudo`, and
exact-rule `sha256sum`. The workflow never invokes a root shell. It then connects
as the deploy identity, verifies the new passwordless `supervisorctl status`
capability, and requires every discovered `fap-queue*` worker to be `RUNNING`.

The v2 receipt records when credential authentication was attempted and
accepted. It also records candidate creation, cleanup attempt, and final
absence. Raw sudo stderr is never uploaded; fixed English authentication errors
map only to a bounded `FAIL_ROOT_BOOTSTRAP_CREDENTIAL_AUTH` status.

An apply failure is terminal. The non-root candidate is cleaned and its absence
is verified, but an installed sudoers rule is never removed automatically. Do
not retry automatically, broaden sudo access, restart Supervisor, deploy, or
modify database/CMS, warm, publication, or discoverability state. Preserve the
failure receipt and design a separately authorized recovery.
