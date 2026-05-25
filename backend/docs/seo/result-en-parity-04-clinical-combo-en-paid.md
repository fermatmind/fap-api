# RESULT-EN-PARITY-04 Clinical Combo Paid Section EN Parity

## Decision

`clinical_combo_en_paid_first_batch_locale_safe`

This PR adds a small backend-owned English first batch for the clinical combo paid action/performance blocks and changes the clinical block selector so English report sections do not silently select `zh-CN` blocks.

## Scope

Covered:

- `paid_action_anxiety_14d.en`
- `paid_action_burnout.en`
- `paid_action_depression_14d.en`
- `paid_action_ocd_erp_start.en`
- `paid_action_perfectionism_14d.en`
- `paid_perf_cm_mistakes.en`
- `paid_perf_da_doubts.en`
- `paid_perf_org_order.en`
- `paid_perf_pe_parental.en`
- `paid_perf_ps_standards.en`
- generated inventory artifact
- focused fail-closed EN paid-block tests

Not covered:

- clinical scoring changes
- diagnosis, treatment, cure, or emergency protocol expansion
- production CMS mutation
- production user result access
- deploy or URL submission
- fap-web changes

## Architecture Finding

Clinical combo paid content is backend content-pack authority:

`backend/content_packs/CLINICAL_COMBO_68/v1/raw/blocks/paid_blocks.json`

Compiled runtime artifact:

`backend/content_packs/CLINICAL_COMBO_68/v1/compiled/blocks.compiled.json`

Before this PR, explicit English paid output had generic EN safety blocks, but the named paid action/performance counterparts identified in RESULT-EN-PARITY-00/01 were missing. The selector also listed `zh-CN` as a secondary candidate for English selection. This PR removes that selector-level Chinese fallback and adds English counterparts for the named paid block keys.

## Claim Boundary

Clinical combo wording remains:

- self-assessment
- self-observation
- non-diagnostic
- not medical advice
- bounded to qualified professional support when distress or impairment is significant

This PR does not add:

- diagnosis
- treatment plan
- cure claims
- medical authority claims
- emergency protocol changes

## Review Control

This is a small first batch, not mass-generated clinical prose. Human clinical/editorial review is still recommended before broad promotion or paid-report marketing expansion.

## Validation

Focused validation:

```bash
cd backend
php artisan test --filter=ResultEnParity04ClinicalComboEnPaid --no-ansi
```

Common validation:

```bash
cd backend
php artisan route:list --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable

cd ..
python3 -m json.tool backend/docs/seo/generated/result-en-parity-04-clinical-combo-en-paid.v1.json >/dev/null
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

`RESULT-EN-PARITY-05` should materialize MBTI backend content package export and classify frontend MBTI clone interpretation copy as non-authoritative / migration-only.
