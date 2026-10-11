# Backend control plane

Current control-plane source must be checked at the exact candidate SHA; historical examples do not bind a new release.

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

## Complete acceptance and bounded consumers

Production baseline discovery requires the complete successful production job, its activation step and immutable actual candidate binding; workflow head_sha and a deploy-skip are not an application baseline. Include unreleased runtime paths when selecting consumers.

For a newly selected publication path, read each environment's real source lineage, missing/existing SEO, published/working pointers and holds. Verify conflict rejection and restore→new-owner publication as well as first publication. Follow the frontend route and shared cache/worker generation rather than stopping at the backend adapter.

Budget the selected consumer sequence cumulatively, including cache repair, discovery checks and reload. An outer timeout does not make a lock acquire wait: inspect actual owner, wait and lease/recheck code. Preserve foreign locks and reread authority after waiting. Use current limits; do not copy a historical job duration as an ETA or relax unrelated child limits.
