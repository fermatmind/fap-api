# Backend control plane

Last verified: 2026-08-10.

## Runtime roles

- Production API: Alibaba ECS running Laravel, Nginx, PHP-FPM, Supervisor workers, Scheduler, and local production Redis; business data resides in Alibaba RDS.
- Staging: separate Alibaba ECS and staging database boundary.
- Production Web: separate Alibaba ECS and separate frontend deployment authority.

Do not encode raw addresses or credentials in Skills.

## Authoritative workflows

- `.github/workflows/ci.yml`: required tests and parity evidence.
- `.github/workflows/deploy.yml` (`Deploy Application`): staging deployment and staging receipt.
- `.github/workflows/deploy-production.yml` (`Deploy API Production`): protected exact-SHA production deployment.
- `.github/workflows/backend-production-verify-only.yml`: bounded read-only production verification.

Read workflow source at the exact candidate SHA. It is authoritative for inputs, deploy modes, approval phrases, timeouts, retry budgets, and receipts.

## Required-check discovery

1. Query the active ruleset targeting `main`.
2. Extract required status-check contexts.
3. Query check runs for the exact SHA.
4. Select the newest completed run per required context.
5. Fail closed on missing, pending, skipped where success is required, or non-success conclusions.

Do not copy required-check names into this reference.

## Environment authority

- Deployment hosts/users/ports/paths and sensitive material must resolve from the corresponding GitHub Environment.
- Keep non-secret topology in Environment variables and sensitive connection material in Environment secrets.
- Repository-level duplicates and retired Tencent/Greenfield source credentials are not runtime authority.
- Preserve the retired-host rejection value when repository guards consume it.

## Release chain

```text
exact main-contained SHA
  -> required checks + parity receipt
  -> Deploy Application staging success
  -> protected production approval
  -> immutable release activation
  -> queue reload and bounded smoke
  -> production receipt/release record
```
