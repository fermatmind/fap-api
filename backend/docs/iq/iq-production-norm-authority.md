# IQ production norm authority v1

## Purpose

`IQ-NORM-01` establishes the backend-only authority boundary for future IQ norm and calibration work. It does not import real norm data, does not unlock IQ estimate claims, and does not change the current beta scoring/report runtime behavior.

## Authority boundary

- Backend `iq_norm_authorities` records are the only source allowed to unlock public IQ estimate, percentile, or confidence interval claims.
- Frontend, CMS, SEO metadata, and paid-report rendering must not infer norm availability from local content, copy, payment state, or item-bank metadata.
- A norm authority is claim-eligible only when it is locked, license-verified, not retired, sample-size gated, and in a claim-eligible status.
- Future importers must run schema validation and dry-run checks before any authority record can be locked.

## Claim-eligible statuses

- `calibrated`
- `norm_table_available`
- `production_normed`

Draft and dry-run states must keep public IQ claims disabled.

## Required gates before SEO/report unlock

- `scale_code` is exactly `IQ_INTELLIGENCE_QUOTIENT`.
- `bank_id`, `norm_table_version`, `population_key`, and `locale` are explicit.
- `sample_size` is at least `500` for public claims.
- `mean`, `standard_deviation`, `min_raw_score`, and `max_raw_score` are numeric, with positive standard deviation.
- `license_verified` is true.
- `locked` is true.
- `source_kind` and `source_ref` are present.
- `retired_at` is empty.

## Deferred to later train items

- `IQ-NORM-02`: dry-run norm table importer and fixture validation.
- `IQ-NORM-03`: scoring/report unlock through backend norm authority.
- `IQ-SEO-RAMP-02`: frontend discoverability expansion gated by backend authority.
