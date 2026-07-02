# Scientific Accuracy Repair v0.2 Normalized Candidates

This directory contains backend agent-readable normalized candidates generated from the local Big Five professional/scientific editorial repair v0.2 package.

## Scope

- Normalize only.
- No staging import.
- No runtime enablement.
- No production import or rollout.
- No fap-web copy.
- No CMS, SEO, or search changes.
- No final `big5_result_page_v2` payload generation.

## Files

- `content_asset_candidates.jsonl`
- `selector_asset_candidates.jsonl`
- `candidate_generation_summary.json`
- `normalization_manifest.json`
- `normalization_validation_summary.json`
- `review_manifest.json`
- `repair_log.json`
- `source_qa_scan.json`
- `source_review.md`
- `SHA256SUMS.txt`

## Required Validation

Run dry-run staging validation without `--allow-staging-write` before any future staging import PR.
