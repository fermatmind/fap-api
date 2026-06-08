# HELP-CMS-SERVICE-FIELDS-PROD-SHA-VERIFY-01

Decision: `BLOCKED_RUNTIME_BEHIND_AND_PUBLISH_SAFETY_PENDING`

This is a read-only production verification for the Help service ContentPage fields lane. It reports the active release SHA and migration status only. It did not deploy, run migrations, mutate CMS rows, publish, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund actions, change payment-provider behavior, or claim Operator approval.

## Result

- Current `origin/main`: `87afe2dfddfab1f8533512b8a2bdf1c347626000`
- Production active revision from `REVISION`: `e812d6f87f8b84b45d2e900d9d3844a0402635bc`
- Production active revision is an ancestor of `origin/main`: yes
- Commits between production active revision and `origin/main`: `45`
- Remote git HEAD: `Unknown`

## Migration Status

| migration | production status |
| --- | --- |
| `2026_06_05_150000_add_help_service_fields_to_content_pages` | `Ran`, batch `45` |
| `2026_06_08_010000_add_publish_safety_fields_to_content_pages` | `Pending` |

## Runtime Evidence

| check | status |
| --- | --- |
| Help service fields migration file | present |
| Publish safety fields migration file | missing |
| `content-pages:import-local-baseline` command | present |
| importer `--upsert` option | present |
| importer `--source-dir` option | present |
| `support_contact` mapping grep | present |
| `policy_version` mapping grep | present |
| `reviewer` mapping grep | present |
| `faq_items` mapping grep | present |
| `schema_enabled` mapping grep | missing |

## Assessment

Production active SHA is now known, but the runtime is not ready for the Help CMS policy sync. The `REVISION` file points to `e812d6f87f8b84b45d2e900d9d3844a0402635bc`, which does not contain the merged Help service runtime commits by local git ancestry. The remote runtime also shows a mixed state: Help service fields migration is already `Ran`, but publish safety migration is `Pending`, and `schema_enabled` importer mapping was not confirmed.

Do not proceed with CMS sync, publish, or production mutation from this state.

## Next Authorization

If you decide to deploy the current latest backend main, use this exact approval phrase:

```text
I explicitly approve backend production deploy for exact SHA 87afe2dfddfab1f8533512b8a2bdf1c347626000 release help-cms-service-fields-prod-20260608-87afe2df.
```

After deploy, run a separate read-only post-deploy verification before CMS sync:

- production `REVISION` equals `87afe2dfddfab1f8533512b8a2bdf1c347626000` or a newer explicitly approved SHA containing it
- Help service fields migration is `Ran`
- publish safety fields migration is `Ran`
- importer mapping includes `support_contact`, `policy_version`, `reviewer`, `faq_items`, and `schema_enabled`

## Validation

```bash
python3 -m json.tool backend/docs/help/generated/help-cms-service-fields-prod-sha-verify-01.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/docs/help/generated/help-cms-service-fields-prod-sha-verify-01.v1.json backend/docs/operations/help-cms-service-fields-prod-sha-verify-2026-06-08.md docs/codex/pr-train.yaml docs/codex/pr-train-state.json
git diff --cached --check
```
