# GitHub Actions lifecycle inventory

Last verified: 2026-08-10
Base SHA: `c8015fac05ae544c8730adac6e18f387c76b2e33`

## Outcome

The repository has 82 workflow files. Phase 1 keeps 14 remotely active:

- 10 long-term control-plane workflows;
- 4 temporary workflows that are still used by active Career 1046 and MBTI title work;
- 68 closed one-time workflows are classified for remote disablement.

The convergence target is 10 active workflows. Temporary project workflows are removed after their exact closeout, and the generic ops convergence PR replaces the separate queue/runtime controls. Disabling is the first archive step because it is reversible and preserves immutable GitHub run evidence while the source-removal PR deletes closed contracts.

The machine-readable authority is [github-actions-lifecycle.v1.json](generated/github-actions-lifecycle.v1.json).

## Long-term surfaces

| Surface | Authority |
|---|---|
| CI and security | `ci.yml`, `codeql.yml` |
| Staging and production deploy | `deploy.yml`, `deploy-production.yml` |
| Production verification and release discovery | `backend-production-verify-only.yml`, `backend-production-release-discovery.yml` |
| Protected runtime operations | queue control and runtime recovery until generic ops convergence |
| Exact content promotion | `content-promotion-automation.yml` |
| Current-published disaster recovery | `backend-greenfield-current-baseline.yml` pending neutral rename |

## Safety rules

- A retired workflow has no `workflow_call` consumer and is not a main required check.
- Remote disablement must not run while that workflow itself is queued or running.
- Disabling never deletes Actions run history or git history.
- Re-enabling a retired workflow requires a new incident-specific audit; old receipts are not fresh authorization.
- Career 1046 and MBTI title controls remain temporary and must not become permanent deployment surfaces.
- Secret cleanup happens only after disabled workflow references are removed from the active set.

## Validation

```bash
cd backend
php artisan test tests/Sre/GithubActionsLifecycleInventoryTest.php
git diff --check
```

Runtime route, migration, curl, and MBTI commands are not applicable: this PR records control-plane lifecycle only and does not modify application behavior.
