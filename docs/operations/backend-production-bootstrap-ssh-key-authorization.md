# Backend Production Bootstrap SSH Key Authorization

This protected control repairs only the missing authorization of the existing
GitHub fap-api production SSH public key for the configured bootstrap actor.
It does not create a new key, replace credentials, edit `sshd_config`, use
sudo, restart SSH, deploy, or change application state.

## Preflight

Set the existing bootstrap account password temporarily as the production
Environment secret `PRODUCTION_ROOT_BOOTSTRAP_SUDO_PASSWORD`. Despite the
historical name, this workflow uses it only for a password-authenticated SSH
session to the account named by `PRODUCTION_ROOT_BOOTSTRAP_SSH_USER`.

The preflight binds the failed v2 Supervisor Root Bootstrap receipt, exact
latest `main`, and unchanged active revision. It derives the public key from
the existing `SSH_PRIVATE_KEY`, records only its SHA-256 identities, and checks
the bootstrap account, home, `.ssh`, `authorized_keys`, deploy lock/processes,
and current key-login rejection. It never writes the remote filesystem.

Only `PASS_SSH_KEY_AUTHORIZATION_PREFLIGHT` with `apply_eligible=true` may be
used to instantiate the artifact-bound approval phrase. A failed password
login, unsafe path, active drift, deploy activity, or already-authorized key
stops without repair.

## Apply

Apply is a separate SSH credential write and requires the exact successful
preflight receipt plus its byte-exact approval phrase. It rechecks the current
`authorized_keys` SHA, creates one same-directory candidate, preserves all
existing entries, adds the exact existing GitHub public key once, and commits
with an atomic rename. It then proves key-only login as the bootstrap account.

No rollback is automatic after the authorized key is committed. Preserve a
failure receipt and use a separately authorized recovery path. Delete the
one-time password secret after every apply attempt. A successful key apply is
followed by a fresh Supervisor Root Bootstrap zero-write preflight; it does not
authorize the sudoers apply.
