# Ops Owner Credential Recovery

Use `Backend Production Ops Owner Credential Recovery` only when an existing
Ops `AdminUser` cannot authenticate and ordinary browser/password-manager checks
have been exhausted. This lane is for solo-operator recovery efficiency; it is
not a general administrator-management path.

## Security boundary

- The workflow is manual-dispatch-only on exact latest `main` and shares the
  production deployment concurrency group.
- The account email and password exist only as the one-time protected
  `production` Environment secrets
  `PRODUCTION_OPS_OWNER_RECOVERY_EMAIL` and
  `PRODUCTION_OPS_OWNER_RECOVERY_PASSWORD`.
- Never put either value in workflow inputs, chat, PR text, shell arguments,
  logs, or artifacts.
- `preflight` is SELECT-only. It emits only opaque SHA-256 identities and
  booleans for existence, active/locked state, password match, TOTP enrollment,
  role count, and recovery eligibility.
- `apply` requires the exact successful preflight run/attempt, opaque account
  and state hashes, active/control SHAs, runner hash, and the workflow-generated
  approval phrase. Preflight evidence expires after 30 minutes.
- Apply updates only the existing `admin_users` row fields `password`,
  `password_changed_at`, `failed_login_count`, `locked_until`, and `is_active`.
  It preserves email, name, TOTP secret/enrollment, roles, and permissions.
- The lane never deploys, migrates, writes CMS/public content, changes
  discoverability, submits search URLs, writes a remote file, or restarts a
  process.
- A failed or ambiguous apply is terminal and must not be retried automatically.

## Operator flow

1. Add both one-time secrets to the GitHub `production` Environment without
   exposing their values to terminal history.
2. Compute the exact runner SHA-256 from current latest `main`.
3. Dispatch `preflight` with exact control and active SHAs plus runner SHA-256.
4. Download and validate the sanitized receipt. If `account_count != 1`, stop;
   this lane never creates or guesses an account.
5. If recovery remains required, copy the exact receipt-bound approval phrase
   and dispatch `apply` once.
6. Verify login and the expected TOTP challenge/organization selection flow.
7. Delete both one-time Environment secrets after every apply attempt, whether
   it succeeds or fails.

## Validation

```bash
php -l backend/scripts/deploy/control_ops_owner_credential_recovery.php
python3 -m unittest tests.ops.test_backend_production_ops_owner_credential_recovery_workflow
git diff --check
```
