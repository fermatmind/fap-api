# RESULT-EN-PARITY-01 Result/Report Asset Catalog Gate

Decision output for this PR: `result_en_parity_01_asset_catalog_gate_ready_for_family_fixes`

This PR adds a backend-owned SeoIntel catalog gate for assessment result/report bilingual parity. It does not translate report prose, change scoring, mutate CMS, deploy, submit URLs, access production user result data, or change fap-web.

## Scope

Covered families:

- `MBTI`
- `BIG5_OCEAN`
- `ENNEAGRAM`
- `EQ_60`
- `RIASEC`
- `SDS_20`
- `CLINICAL_COMBO_68`
- `IQ_INTELLIGENCE_QUOTIENT`

The gate exports asset availability and classifies each asset with:

- `has_zh`
- `has_en`
- `missing_en`
- `fallback_to_zh_detected`
- `presentation_label_only`
- `interpretation_copy`
- `sensitive_claim_boundary`
- `fail_closed_for_en`

## Authority

Backend scoring, CMS, and result/report asset catalogs remain authority. fap-web fallback content is not authority. Frontend labels can be recorded as presentation labels, but frontend interpretation copy is not acceptable as report authority.

## Fail-Closed Rule

For English result/report rendering, an asset must fail closed when:

- the asset is interpretation copy;
- the English counterpart is missing;
- the only available interpretation prose is zh-CN, or a zh-CN fallback path was detected.

Presentation labels are distinguished from interpretation prose and do not block the gate when they have explicit zh/en availability.

## Generated Artifact

Generated artifact:

- `backend/docs/seo/generated/result-en-parity-01-asset-catalog-gate.v1.json`

Summary from the generated artifact:

- families: 8
- assets: 47
- missing English assets: 32
- zh-CN fallback detections: 29
- presentation-label-only assets: 8
- interpretation-copy assets: 39
- sensitive/claim-boundary assets: 26
- fail-closed assets: 32

## Blocking Families

The gate intentionally reports fail-closed issues for the known gaps from RESULT-EN-PARITY-00:

- MBTI authoritative package export / legacy fallback copy / frontend clone interpretation copy.
- Big Five ResultPageV2 route matrix, coupling, scenario action, facet, and canonical profile assets.
- Enneagram registry catalogs.
- RIASEC lifecycle, share/PDF/history, FAQ, technical note, and method-boundary copy.
- Clinical combo paid action/performance blocks.
- IQ dimension labels plus pro PDF/certificate contracts.

EQ and SDS current repo-visible compiled packs have zh/en parity in this gate, but sensitive surfaces remain marked with claim-boundary metadata.

## Deferred Items

This PR does not implement the family-specific English assets. Those stay in the follow-up train:

- `RESULT-EN-PARITY-02` for RIASEC.
- `RESULT-EN-PARITY-03` for IQ.
- `RESULT-EN-PARITY-04` for clinical combo paid sections.
- `RESULT-EN-PARITY-05` for MBTI content export and frontend de-authoring.
- `RESULT-EN-PARITY-06` for Big Five ResultPageV2.

Repository rule impact: result/report interpretation copy is explicitly backend/CMS authoritative; frontend fallback is non-authoritative. No CMS content ownership or runtime rendering behavior is changed in this PR.
