# Backend control plane

Last verified: 2026-08-10.

## Runtime roles

- Production API: Alibaba ECS running Laravel, Nginx, PHP-FPM, Supervisor workers, Scheduler, and local production Redis; business data resides in Alibaba RDS.
- Staging: separate Alibaba ECS and staging database boundary.
- Production Web: separate Alibaba ECS and separate frontend deployment authority.

Do not encode raw addresses or credentials in Skills.

## Authoritative workflows

- `.github/workflows/ci.yml`: main-push path classification, focused validation, and exact-SHA receipt.
- `.github/workflows/deploy.yml`: serialized exact-SHA staging, smoke, production, post-activation smoke, and bounded LKG restoration.
- `.github/workflows/nightly.yml`: full regression, security, content consistency, dependency, and performance checks.
- `.github/workflows/recovery.yml`: the only manual workflow, reserved for diagnosis/LKG/exact-SHA recovery after automatic restoration fails.

Read workflow source at the exact candidate SHA. It is authoritative for inputs, deploy modes, approval phrases, timeouts, retry budgets, and receipts.

## CI receipt discovery

1. Resolve the exact main push SHA and its successful `ci.yml` run.
2. Require exactly one unexpired `trunk-validation-<SHA>` artifact and verify its GitHub artifact digest.
3. Verify the receipt schema, SHA, CI run identity, result, and path classification.
4. Fail closed on missing, ambiguous, expired, wrong-SHA, wrong-run, or non-success evidence.

## Environment authority

- Deployment hosts/users/ports/paths and sensitive material must resolve from the corresponding GitHub Environment.
- Keep non-secret topology in Environment variables and sensitive connection material in Environment secrets.
- Repository-level duplicates and retired Tencent/Greenfield source credentials are not runtime authority.
- Preserve the retired-host rejection value when repository guards consume it.

## Release chain

```text
exact pushed SHA
  -> path-aware CI + exact-SHA receipt
  -> deploy.yml staging success and smoke
  -> automatic immutable production activation
  -> bounded smoke and automatic LKG restoration on committed failure
  -> production receipt/release record
```
