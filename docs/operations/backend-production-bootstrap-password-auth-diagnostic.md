# Backend Production Bootstrap Password Auth Diagnostic

This protected, manual workflow distinguishes a rejected bootstrap password
from a production SSH policy that does not offer password authentication. It
is a diagnostic authority only. It never changes `authorized_keys`, `sshd`, an
account password, sudo policy, application state, or production services.

## Bound evidence

The workflow runs only from exact latest `main` in the `production`
Environment and the shared production deployment concurrency group. It binds
the failed `Backend Production Bootstrap SSH Key Authorization` run
`31260377996` attempt `1`, its artifact digest
`6ac8c6c7dd8687438c959806dfd9ec8420a32bf803ecb26b132c89a22ee00973`,
receipt SHA-256
`72ed1c5ff65cca98eb1807ba2554a5aacb4542acbb647e9784d7340a4cadc802`,
source control SHA `226c95c20a421b03e2c367792d213da357ff51d6`, and active
revision `ff8e9b5d2021f171cbceeb7a33677307c74df58c`.

Before dispatch, re-save the intended bootstrap account password in the
production Environment secret `PRODUCTION_ROOT_BOOTSTRAP_SUDO_PASSWORD`.
Re-saving the secret does not reset the server password and GitHub cannot read
the value back. The protected authentication result is the only proof that the
secret and server account agree.

## Diagnostic sequence

The first SSH connection disables public-key, password, keyboard-interactive,
GSSAPI, and host-based client authentication. It sends no password. OpenSSH
debug stderr is captured to a mode-`0600` runner-temporary file, reduced by the
repository classifier to an allowlisted status, and deleted immediately.
Neither raw stderr nor routing values enter logs, summaries, or artifacts.
Both SSH operations are evaluated as Bash conditional commands because a
non-zero authentication result is expected diagnostic input; this lets the
sanitized classifier handle it without invoking the unexpected-runner-error
trap.

If the server explicitly offers `password`, the workflow performs exactly one
password-only connection using the protected secret through `SSH_ASKPASS`.
Public-key and keyboard-interactive authentication stay disabled. A successful
session emits only the remote username SHA-256 and whether the active
`REVISION` matches the expected value. Its stderr capture and askpass helper are
deleted before the receipt is finalized.

The receipt schema is
`backend.production_bootstrap_password_auth_diagnostic.v1`. Its terminal
statuses are:

- `PASS_PASSWORD_AUTH_DIAGNOSTIC`
- `BLOCKED_PASSWORD_AUTH_NOT_OFFERED`
- `FAIL_SECRET_PASSWORD_REJECTED`
- `FAIL_TRANSPORT_OR_HOST_KEY`
- `FAIL_REMOTE_IDENTITY`
- `FAIL_ACTIVE_REVISION`
- `FAIL_DIAGNOSTIC_PROTOCOL`

The immutable artifact is uploaded before terminal enforcement. All non-pass
statuses intentionally fail the workflow after the sanitized receipt exists.
The receipt records only boolean checks, opaque identity hashes, capture
cleanup, and explicit zero production-write counters.

## Follow-up boundary

A pass permits a separate fresh `Backend Production Bootstrap SSH Key
Authorization` zero-write preflight. It does not authorize an `authorized_keys`
apply. When password authentication is not offered, use a separately protected
cloud/root read-only `sshd` and account-policy diagnosis. When password is
offered but the re-saved secret is rejected, stop and verify the account
password/lock state through the cloud console. Do not automatically retry this
diagnostic.
