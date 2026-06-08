# HELP-CMS-SERVICE-FIELDS-PROD-RELEASE-01

Decision: `DEPLOY_AUTHORIZATION_BLOCKED_PRODUCTION_ACTIVE_SHA_UNKNOWN`

This is a fresh deploy-readiness check for the Help service ContentPage fields release. It refreshes the current exact `origin/main` SHA and applies the production deploy targeting SOP. It does not deploy, run migrations, mutate CMS rows, publish, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund actions, change payment-provider behavior, or claim Operator approval.

## Current Target

- Current `origin/main`: `14f92cba66da60acf6c1bc5777058ceb33323319`
- Deploy class if production active SHA is known and behind target: `deploy_latest_main`
- Required Help runtime commits included:
  - PR #1956: `9cae1f45ee55acf11df43376e80387957c2049a3`
  - PR #1959: `13e62a0389124e4acb90f4b7467a26ea996cf24e`
  - PR #1962: `0dd12d8751b328cf502a28e586597d1ed4a4246f`
- Newer main commits included:
  - PR #1963: `a5509759`
  - PR #1965: `3acd4de7`
  - PR #1964: `01de36c41ff5180e269ca324bb5cc666eac2babf`
  - Unknown PR: `8a905835`
  - PR #1967: `ee040639233985adce6cc599bb8b3a38f7d87750`
  - PR #1969: `14f92cba66da60acf6c1bc5777058ceb33323319`

## Public Production Evidence

- API root status: `200`
- Public `/api/healthz` status: `404`
- Public draft Help content page route status: `404`
- Production active SHA: `Unknown`
- Production contains target SHA: `Unknown`

The public Help page 404 is compatible with draft/non-public state and does not prove runtime SHA.

## Decision

Do not deploy from this readiness pass. The production deploy targeting SOP requires current production release SHA before accepting a deploy approval. Public read-only checks did not expose that SHA.

## Next Safe Authorization

```text
I authorize Codex to run HELP-CMS-SERVICE-FIELDS-PROD-SHA-VERIFY-01 using an approved read-only production method that reports only the active release SHA and migration status for the Help service field migrations, without reading secret/env/cookie/token values and without deploy, CMS mutation, publish, search submission, private URL access, payment/refund action, payment-provider change, or Operator approval claim.
```

## Stale Deploy Prompt

Do not use this without refreshing `origin/main` and production active SHA again:

```text
I explicitly approve backend production deploy for exact SHA 14f92cba66da60acf6c1bc5777058ceb33323319 release help-cms-service-fields-prod-release-20260608-14f92cba.
```

## Validation

```bash
git rev-parse origin/main
git merge-base --is-ancestor 9cae1f45ee55acf11df43376e80387957c2049a3 origin/main
git merge-base --is-ancestor 13e62a0389124e4acb90f4b7467a26ea996cf24e origin/main
git merge-base --is-ancestor 0dd12d8751b328cf502a28e586597d1ed4a4246f origin/main
python3 -m json.tool backend/docs/help/generated/help-cms-service-fields-prod-release-01.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/docs/help/generated/help-cms-service-fields-prod-release-01.v1.json backend/docs/operations/help-cms-service-fields-prod-release-2026-06-08.md docs/codex/pr-train.yaml docs/codex/pr-train-state.json
git diff --cached --check
```
