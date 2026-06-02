# IQ_BETA_50_ORIGINAL Bank Specification

Date: 2026-06-02

Scope: backend-authoritative planning contract for the future 50-item FermatMind IQ beta bank. This PR defines the manifest/spec only. It does not import final questions, answer keys, scoring norms, runtime binding, CMS copy, frontend take behavior, or commerce unlock.

## 1. Bank identity

| Field | Value |
| --- | --- |
| Bank id | `IQ_BETA_50_ORIGINAL` |
| Scale code | `IQ_INTELLIGENCE_QUOTIENT` |
| Legacy compatibility | `IQ_RAVEN` remains an input alias only; it is not user-facing copy. |
| Frontend display key | `beta_50` |
| Relationship to beta30 | `IQ_BETA_30_ORIGINAL` remains the current available free beta form. |
| Item count target | `50` |
| Option count target | `6` options per item, `A` through `F` |
| Runtime status | `future_placeholder_spec_only` |
| Public take enabled | `false` |
| Commerce requirement | None in this PR; commerce unlock remains deferred until backend authority exists. |

## 2. Target dimension distribution

| Dimension | Count | Purpose |
| --- | --- | --- |
| `VSPR` | `22` | Visual-spatial pattern reasoning, including progressive matrices and visual sequences. |
| `VSI` | `16` | Visual-spatial insight, rotation, overlay, symmetry, and grouping. |
| `NPR` | `12` | Numerical pattern reasoning using language-light count, recurrence, and symbolic rules. |

## 3. Target item-family distribution

| Family | Count | Notes |
| --- | --- | --- |
| `matrix_3x3` | `16` | Main progressive matrix signal. |
| `matrix_2x2` | `6` | Warm-up and analogy items. |
| `series` | `8` | Figure sequence continuation. |
| `odd_one_out` | `6` | Shared-rule exception detection. |
| `rotation` | `5` | Rotation and mirror-rejection tasks. |
| `overlay` | `5` | Union, intersection, subtraction, or XOR-style composition. |
| `numeric_pattern` | `4` | Count-based or symbolic recurrence. |

## 4. Launch gates

Beta50 must stay unavailable until all gates pass:

- final item import
- backend-only answer key
- scoring spec
- norm authority and calibration policy
- copyright gate
- ambiguity gate
- provenance gate
- public payload redaction gate
- frontend renderer contract gate

## 5. Explicit non-goals for this PR

- no `items.json`
- no `answer_key.json`
- no `scoring_spec.json`
- no runtime binding
- no public take entry
- no sitemap, llms, or JSON-LD expansion
- no CMS editorial copy
- no payment or entitlement change

## 6. Frontend handoff

Frontend may render `beta_50` as a coming-soon or future validation placeholder only. It must not expose a start/take CTA until backend imports the final bank and marks the form take-enabled through an explicit follow-up PR.
