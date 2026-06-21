# Enneagram a9fd Full Candidate Export After 1R-A To 1R-H

Status: `PASS_FULL_CANDIDATE_EXPORT_DRY_RUN`

This document records the post-1R-A-through-1R-H full Enneagram result-page candidate export dry run. It is evidence-only. It does not commit generated candidate payload artifacts, import an inactive release, activate a release, switch runtime state, write production data, or change frontend behavior.

## Scope

- PR: `Enneagram: record full a9fd candidate export after 1R batches`
- Branch: `codex/enneagram-full-candidate-export-evidence-01`
- Candidate reference: `<tmp>/fm_enneagram_a9fd_renderable_20260619`
- Candidate manifest SHA expected: `a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0`
- Candidate manifest SHA actual: `a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0`
- Runtime registry manifest SHA expected: `ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f`
- Runtime registry manifest SHA recorded: `ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f`
- Total payload count: `630`

## Command

```bash
PHASE8B_CANDIDATE_DIR=<tmp-a9fd-copy> \
PHASE8B1_OUTPUT_DIR=<tmp-output> \
php artisan enneagram:export-production-equivalent-candidate-payloads --json
```

## Result

The command exited successfully with:

- `verdict=PASS_WITH_PAYLOAD_SOURCE_GENERATED_BUT_FRONTEND_RETRY_NOT_RUN`
- `total_payload_count=630`
- `candidate_manifest_hash_actual=a9fd3eb474ea2ca0130d06ad2b1640305d9160ee1a74e559ad4f60bfc4db56c0`
- `runtime_registry_manifest_hash_recorded=ac5bdaab3c761b0d01a56f92679aa58341110d64de0f47a1fa0062b64f76f97f`
- `production_import_happened=false`
- `full_replacement_happened=false`

## Payload Matrix

| Matrix | Count |
| --- | ---: |
| baseline | 36 |
| low_resonance | 108 |
| partial_resonance | 90 |
| diffuse_convergence | 108 |
| close_call_pair | 36 |
| scene_localization | 162 |
| fc144_recommendation | 90 |
| total | 630 |

## Validator Checks

| Check | Result |
| --- | --- |
| Candidate manifest hash remains current a9fd baseline | PASS |
| Runtime registry hash matches expected contract | PASS |
| Candidate payload count equals 630 | PASS |
| Source mapping failure count | `0` |
| Missing source mapping count | `0` |
| Fallback source count | `0` |
| Blocked source count | `0` |
| Duplicate selection count | `0` |
| Branch provenance mismatch count | `0` |
| Legacy deep-core residual count | `0` |
| Metadata leakage count | `0` |
| FC144 boundary violation count | `0` |

## Negative Guarantees

- No candidate payload artifacts are committed in this PR.
- No inactive import happened.
- No production import happened.
- No activation happened.
- No runtime switch happened.
- No CMS write happened.
- No frontend change happened.
- No candidate baseline reset is required because the full export still matches `a9fd`.

## Follow-Up

The next gate may proceed to Web Phase8C rendered QA using the a9fd candidate directory. It must remain QA-only: no backend import, no runtime activation, no production write, and no frontend content fallback.
