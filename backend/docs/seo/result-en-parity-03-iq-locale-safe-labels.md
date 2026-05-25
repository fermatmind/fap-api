# RESULT-EN-PARITY-03 IQ Locale-Safe Report Builder Labels

## Decision

`iq_locale_safe_labels_backend_catalog_ready`

This PR moves IQ report labels into a backend-owned locale catalog and makes `IqReportBuilder` consume labels by locale. It preserves the existing Chinese default while allowing explicit English report payloads to render English dimension and IQ Pro labels without silently falling back to Chinese.

## Scope

Covered:

- `iq.dimensions.visual_spatial_insight.en`
- `iq.dimensions.visual_spatial_pattern_reasoning.en`
- `iq.dimensions.numeric_pattern_reasoning.en`
- runtime canonical alias `numerical_pattern_reasoning`
- `iq_pro.pdf_payload.en`
- `iq_pro.certificate_payload.en`
- focused fail-closed EN label tests
- generated inventory artifact

Not covered:

- IQ scoring algorithm changes
- clinical or definitive IQ claims
- PDF/certificate file generation
- fap-web changes
- production CMS mutation
- production user result access
- deploy or search submission

## Architecture Finding

Before this PR, `IqReportBuilder` hardcoded Chinese labels in `DIMENSIONS`, which meant an English IQ report could still expose Chinese labels even when the report payload itself was otherwise locale-specific. The builder now reads labels from its backend-owned `LABEL_CATALOG` constant:

`backend/app/Services/Report/IqReportBuilder.php::LABEL_CATALOG`

English behavior:

- `en-US` and other `en*` inputs normalize to `en`.
- English labels are read from the backend catalog.
- Missing English labels return `null` instead of falling back to `zh-CN`.
- `label_catalog.fallback_policy` records `missing_label_returns_null_no_zh_fallback`.

Default Chinese behavior:

- no caller changes required
- existing `zh-CN` output remains available through the same backend catalog

## Claim Boundary

IQ language remains bounded as an online estimate / confidence-bound result. This PR does not add or support:

- clinical IQ diagnosis
- definitive intelligence measurement
- certified IQ authority
- globally most accurate claims
- treatment or medical diagnosis language

## Validation

Focused validation:

```bash
cd backend
php artisan test --filter=ResultEnParity03IqLocaleSafeLabels --no-ansi
php artisan test --filter=IqReportBuilder --no-ansi
```

Common validation:

```bash
cd backend
php artisan route:list --no-ansi
vendor/bin/pint --test
composer validate --strict
composer audit --locked --no-interaction --ignore-unreachable

cd ..
python3 -m json.tool backend/docs/seo/generated/result-en-parity-03-iq-locale-safe-labels.v1.json >/dev/null
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

`RESULT-EN-PARITY-04` should handle clinical combo paid-section English parity with self-assessment, non-diagnostic, non-treatment boundaries.
