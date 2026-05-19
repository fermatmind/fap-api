# RIASEC Full Content Pack 03 Preflight

Date: 2026-05-19

Asset package: `riasec_full_content_assets_v7_3_final_preflight_candidate.zip`

Target asset: `04_pair_blend_15_pairs_v1.zh-CN/pair_blend_15_pairs_v1.zh-CN.jsonl`

## Result

Decision: `CONDITIONAL GO`

The V7.3 pair blend asset can proceed to `RIASEC-FULL-CONTENT-PACK-03` backend import preflight gate, provided the import PR performs the documented normalization below and keeps missing/invalid content fail-closed.

## Verified In This PR

- The V7.3 pair asset parses as JSONL.
- The asset contains exactly 15 unordered RIASEC pair keys:
  - `R_I`, `R_A`, `R_S`, `R_E`, `R_C`
  - `I_A`, `I_S`, `I_E`, `I_C`
  - `A_S`, `A_E`, `A_C`
  - `S_E`, `S_C`, `E_C`
- Every row includes the required source fields needed to normalize into a backend `pair_blend_copy` slot.
- User-facing pair copy has no positive forbidden claim hit under the preflight scanner.
- User-facing pair copy has no targeted technical-key exposure.
- The normalized backend slot shape is complete for `RiasecContentRegistrySlotContract` and `RiasecDeepCopySlotRegistry`.
- One schema gap is documented below: the current runtime forbidden-phrase validator uses whole-slot substring scanning and flags explicit negative boundary copy as `forbidden_claim_phrase_non_ascii`.

## Documented Schema Gap For PACK-03

The V7.3 asset contains boundary copy that names disallowed uses in a negative form, for example wording equivalent to "this is not a job-competence basis." The preflight user-facing scanner classifies those as allowed boundary hits, not positive claims.

Current backend validation is stricter and scans the complete normalized slot JSON by substring. That means a negated boundary phrase can still produce `forbidden_claim_phrase_non_ascii` even when it is not a user-facing positive claim.

PACK-03 must resolve this before import by either:

- adding boundary-aware forbidden-claim classification to the backend content slot validator; or
- keeping negative constraint phrase lists in governance/internal fields that do not block authored user-facing pair copy.

This is why the decision is `CONDITIONAL GO`, not unconditional `GO`.

## Required Import Normalization For PACK-03

The V7.3 editorial asset intentionally remains an editorial candidate. The import PR must normalize these values before runtime emission:

| Source field | Runtime slot field | Import normalization |
|---|---|---|
| `asset_version` | `content_version` | copy value |
| `review_status=content_review_v7_3_final_preflight_candidate` | `review_status` | map to `content_review` until owner signoff |
| `source_status=reviewed_content_copy_candidate` | `source_status` | map to `reviewed_content_copy` only if import gate accepts the owner-reviewed candidate |
| `evidence_level=expert_reviewed_content_copy_candidate` | `evidence_level` | map to `expert_reviewed` only if import gate accepts the owner-reviewed candidate |
| `content_status=content_review_v7_3_final_preflight_candidate` | `content_status` | map to `authored` only for rows that pass validation |
| `dimensions` | `applicable_dimensions` | copy list |
| `pair_key` | `applicable_codes` | wrap as one-item list |

This PR does not perform the runtime import.

## Import No-Go Conditions

PACK-03 must stop if any of the following are true:

- Fewer than 15 pair rows are present.
- Any pair key is missing or unsupported.
- A required pair field is blank.
- A forbidden user-facing claim is found.
- A technical key appears in a user-facing field.
- Import requires frontend fallback copy.
- Import requires scorer, question pack, Holland Code, raw-delta, report/share/PDF/history, career matching, feedback mutation, analytics, or production data changes.

## Runtime Boundary

Pair blend copy explains occupational-interest combination dynamics. It is not a personality identity, ability proof, career recommendation, job-fit claim, ranking, hiring signal, success prediction, or 140Q accuracy claim.
