# Production Deploy Targeting SOP

This SOP defines the fap-api production deploy targeting rule. It exists to prevent a requested PR or SHA deploy from accidentally becoming a "latest main" deploy.

## Required Target Resolution

Before any production deploy approval is requested or accepted, the operator must resolve:

- requested PR number, if any
- requested target commit as a full 40-character SHA
- current `origin/main` SHA
- current production release SHA
- latest merged PRs between production and `origin/main`
- whether deploying the requested target would include newer merged PRs
- whether the deploy command path deploys `origin/main` or an exact pinned revision

Do not treat a PR number, branch name, release name, or short SHA as sufficient deploy authority.

## Deploy Decision Classes

- `no_deploy_needed`: production already contains the full target SHA.
- `deploy_latest_main`: target SHA equals `origin/main`; deploy may proceed after exact confirmation.
- `bounded_exact_sha_required`: target SHA is behind `origin/main`; stop unless the user explicitly approves excluding newer commits and the deploy command can pin the exact SHA.
- `blocked_ambiguous_target`: the request does not uniquely resolve to one full target SHA.
- `blocked_tooling_gap`: the requested bounded deploy cannot be honored by the available deploy command.

If newer commits exist after the target SHA, list them as included or excluded before deploy approval. Never hide that distinction behind "latest main".

## Confirmation Phrases

For a normal latest-main production deploy:

```text
I explicitly approve backend production deploy for exact SHA <40-character-sha> release <release-name>.
```

For a bounded deploy where the target SHA is behind `origin/main`:

```text
I explicitly approve bounded backend production deploy for exact SHA <40-character-sha>, excluding newer main commits <short-sha/pr-list>, release <release-name>.
```

For post-deploy production data mutation:

```text
I explicitly approve production article baseline upsert for slugs <slug-list> after verifying production contains SHA <40-character-sha>.
```

Post-deploy mutation must use a separate confirmation from code deployment.

## Stop Rules

Stop before deploy if:

- the target SHA is ambiguous
- the approval phrase does not match the resolved SHA
- `origin/main` has newer commits and the user has not explicitly approved whether they are included or excluded
- the user requests a bounded deploy but the deploy command cannot pin the exact SHA
- production does not yet contain the required SHA and a post-deploy mutation is requested
- read-only production verification cannot determine the active deployed SHA

## Repository Rule Impact

This SOP changes only deploy governance. It does not change runtime code, database schema, content authority, public API behavior, sitemap/llms behavior, or production deploy scripts.
