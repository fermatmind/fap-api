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
the existing `SSH_PRIVATE_KEY`, rejects ambiguous or malformed agent output,
and records only SHA-256 identities. The password session disables public-key
and keyboard-interactive authentication. A separate key probe uses
`-F /dev/null`, `IdentitiesOnly=yes`, and the exact normalized public key as its
only identity; agent and user SSH configuration cannot satisfy that probe.

The remote inventory checks the bootstrap identity, active revision, deploy
lock/processes, and the complete path contract. The home must be owned by the
actor, traversable, and not group/world writable. `.ssh` must be an owned
`0700`-equivalent non-symlink directory. `authorized_keys` must be absent or an
owned, readable, single-link regular file no larger than 1 MiB, on the same
device, with safe mode and a final LF when non-empty. Blank/comment lines are
ignored, supported options are parsed, and malformed active lines fail closed.
It never writes the remote filesystem or creates a sudo timestamp.

Only `PASS_SSH_KEY_AUTHORIZATION_PREFLIGHT` with `apply_eligible=true`, target
count zero, and a rejected exact-key probe may instantiate the artifact-bound
approval phrase. A target fingerprint that is present but cannot authenticate
returns `BLOCKED_TARGET_KEY_PRESENT_BUT_UNUSABLE`; it is never repaired by
adding a duplicate. A failed password login, malformed remote protocol, unsafe
path, active drift, or deploy activity stops without repair.

## Apply

Apply is a separate SSH credential write and requires the exact successful
v2 preflight receipt plus its byte-exact approval phrase. It rechecks identity,
active revision, lock/process state, owner, mode, link count, size, device,
final LF, target count, and the current `authorized_keys` SHA. The candidate is
created with no-clobber semantics in `.ssh`; a pre-existing candidate is never
deleted. Cleanup is allowed only while the candidate's device/inode still
matches the file created by this run. Existing bytes are preserved, exactly one
canonical key line is appended, mode is set to `0600`, and GNU `mv -T` performs
the same-directory atomic replacement. The post-write readback again uses only
the exact normalized public key.

Every terminal path emits a sanitized v2 receipt before the workflow is made to
fail, and the artifact upload precedes terminal enforcement. Transport loss
after an apply starts is represented as `write_state=ATTEMPTED_UNKNOWN`; the
receipt does not claim that no write occurred. Once atomic rename commits,
later verification failure records `writes_committed=true` and never rolls the
file back. Preserve that receipt and use a separately authorized recovery path.
Delete the one-time password secret after every apply attempt. A successful key
apply is followed by a fresh Supervisor Root Bootstrap zero-write preflight; it
does not authorize the sudoers apply.
