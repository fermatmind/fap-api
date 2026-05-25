# RESULT-EN-PARITY-02 RIASEC Result/Report English Asset Catalog

## Decision

`riasec_lifecycle_en_assets_prepared_fail_closed_with_deferred_deep_assets`

This PR prepares backend-owned English counterparts for the RIASEC lifecycle assets used by result/report share, PDF, history, FAQ, and method-boundary surfaces. Existing default Chinese runtime behavior remains unchanged. English runtime access is explicit and fail-closed: an English request reads `.en` assets and does not silently fall back to `zh-CN`.

## Scope

Covered:

- `share_pdf_history_v1.en`
- `faq_v1.en`
- `faq_v1.en.md`
- `technical_note_user_summary_v1.en`
- `professional_method_boundary_v1.en`
- `RiasecLifecycleCopyService` locale-aware lookup
- generated inventory artifact
- focused parity tests

Not covered:

- full RIASEC deep-copy English report prose
- pair-blend, Top 3 chain, activity examples, aspirations, disagreement, Action Lab, occupation examples, near-tie, low-quality, profile-shape, or confidence-copy translation
- CMS mutation
- production user result access
- fap-web changes
- deployment or search submission

## Architecture Finding

RIASEC lifecycle copy is backend-owned and file-backed under `backend/content_assets/riasec`. Before this PR, the lifecycle service loaded only `zh-CN` files. This PR keeps `zh-CN` as the default locale but adds explicit `en` lookup paths.

For English:

- `en-US` and other `en*` inputs normalize to `en`.
- English assets are read from `.en` files.
- Missing English assets return unavailable/empty payloads rather than using `zh-CN`.
- `frontend_fallback_allowed` remains `false`.
- `missing_content_behavior` remains `omit_module_fail_closed`.

## Prepared English Asset Matrix

| Asset | Surface | Status | EN fallback policy |
|---|---|---|---|
| `share_pdf_history_v1.en` | share / PDF / history | draft authority candidate, human review required | no zh fallback |
| `faq_v1.en` | result / report FAQ | draft authority candidate, human review required | no zh fallback |
| `faq_v1.en.md` | FAQ markdown reference | draft authority candidate, human review required | no zh fallback |
| `technical_note_user_summary_v1.en` | result/report method summary | draft authority candidate, human review required | no zh fallback |
| `professional_method_boundary_v1.en` | professional method boundary | draft authority candidate, human review required | no zh fallback |

## Deferred RIASEC Assets

The following remain intentionally deferred because they are substantial interpretation prose and require reviewed content batches:

- `140q_task_environment_role_v1`
- `activity_task_examples_v1`
- `aspirations_calibration_v1`
- `dimension_deep_copy_v1`
- `disagree_path_v1`
- `feedback_action_lab_v1`
- `low_quality_cautious_reading_v1`
- `near_tie_alternate_code_copy_v1`
- `next_exploration_nodes_v1`
- `occupation_examples_boundary_v1`
- `pair_blend_15_pairs_v1`
- `profile_shape_copy_v1`
- `top3_code_chain_strategy_v1`
- `top_code_confidence_copy_v1`

## Claim Boundary

RIASEC remains a Holland interest assessment and six-dimensional result vector. This PR does not turn RIASEC into an active career recommendation engine.

Allowed wording:

- interest signal
- work-activity tendency
- exploratory guidance
- low-risk validation step
- not an ability score
- not a career recommendation

Forbidden wording:

- precise career recommendation
- best career for you
- hiring suitability
- job fit guarantee
- career success prediction
- salary prediction
- diagnosis / treatment / cure

## Validation

Required focused test:

```bash
cd backend
php artisan test --filter=ResultEnParity02RiasecEnAssets --no-ansi
```

Common validation:

```bash
cd backend
php artisan route:list --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable

cd ..
python3 -m json.tool backend/docs/seo/generated/result-en-parity-02-riasec-en-assets.v1.json >/dev/null
python3 - <<'PY'
import yaml, json
yaml.safe_load(open('docs/codex/pr-train.yaml'))
json.load(open('docs/codex/pr-train-state.json'))
print('manifest/state parse ok')
PY
git diff --check
git diff --cached --check
```

## Next PR

`RESULT-EN-PARITY-03` should handle IQ locale-safe report builder labels and keep IQ wording bounded as online estimate / confidence-bound, not clinical IQ authority.
