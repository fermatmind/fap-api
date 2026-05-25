# RESULT-EN-PARITY-05 MBTI Content Export and Frontend De-Authoring

Decision output for this PR: `result_en_parity_05_mbti_content_export_ready_for_reviewed_asset_batch`

This PR materializes a backend-owned MBTI result/report asset inventory and explicitly classifies fap-web MBTI clone interpretation content as non-authoritative migration-only content. It does not translate MBTI report prose, change MBTI scoring, mutate CMS, deploy, submit URLs, access production user result data, or modify fap-web.

## Scope

Covered MBTI surfaces:

- Result structured type code and axis scores.
- Report canonical section registry.
- External backend content package read path.
- Legacy generated fallback copy.
- Share public projection summary.
- PDF report payload.
- Email result/report summary.
- My Results card summary.
- fap-web desktop clone base and variant content as reference-only migration artifacts.

Generated artifact:

- `backend/docs/seo/generated/result-en-parity-05-mbti-content-export.v1.json`

## Authority Finding

Backend scoring, CMS/personality profile authority adapters, result/report payload builders, and backend content package read paths remain authority.

fap-web clone content is not authority. The reference-only scan found:

- 16 base zh clone content files under `components/result/mbti/clone/content/*.zh.ts`.
- 32 variant zh patch files under `components/result/mbti/clone/content/variants/*.zh.ts`.
- `components/result/mbti/clone/content/index.ts` already labels the registry as a migration artifact and historical seed source.

This PR records that classification in fap-api so downstream result/report parity work cannot treat those frontend clone files as authoritative English source material or a fallback source.

## Backend Export

The generated artifact exports:

- 32 canonical MBTI report section keys from `MbtiCanonicalSectionRegistry`.
- Backend source files that own scoring, external content-package loading, legacy report assets, CMS personality profile authority, and public projections.
- Missing English keys that must fail closed rather than render zh-CN prose.

The export is intentionally a manifest/inventory, not a runtime report rewrite.

## Missing English Keys

Explicitly deferred English assets:

- `backend_external_content_package_export_required`
- `legacy_mbti_generated_fallback_copy.en`
- `frontend_mbti_clone_content_base.en`
- `frontend_mbti_clone_content_variants.en`
- `mbti.share.public_projection_summary.en`
- `mbti.pdf.report_payload.en`
- `mbti.email.result_report_summary.en`
- `mbti.my_results.card_summary.en`

## Fail-Closed Rule

English MBTI result/report interpretation copy must not silently fall back to zh-CN. If a backend-owned English interpretation asset is missing, the module must fail closed, omit the unavailable copy, or show an explicitly unavailable state. Presentation labels are separate and do not make frontend interpretation prose authoritative.

## Claim Boundary

MBTI career wording must stay non-deterministic:

- allowed: workstyle tendency, career direction reference, exploratory guidance, snapshot-based support, evidence-backed explanation;
- forbidden: precise career recommendation, best career for you, hiring suitability, job fit guarantee, career success prediction, salary prediction, turnover prediction.

## Deferred Items

This PR does not generate the full MBTI English report content package. The next backend content work should create a reviewed small-batch English import package or draft package before expanding coverage.

If fap-web runtime still consumes clone interpretation copy as public result/report content, that should be handled in a linked fap-web PR after this backend authority export. This fap-api PR does not modify fap-web.

Repository rule impact: MBTI result/report interpretation copy is reaffirmed as backend/CMS authoritative. fap-web clone content is recorded as non-authoritative migration-only material. No runtime ownership is transferred to frontend code.
