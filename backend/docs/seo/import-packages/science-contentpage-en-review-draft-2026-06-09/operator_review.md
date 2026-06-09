# Operator Review Notes

Package: Science ContentPage English Review Draft Package
Date: 2026-06-09
Status: draft only, not approved for CMS import or publication.

## Scope

This package contains English draft counterparts for five approved zh-CN CMS `content_pages`:

- `science` -> `Assessment Science`
- `item-design-notes` -> `Item Design Notes`
- `reliability-validity` -> `Reliability and Validity`
- `data-privacy` -> `Data Notes`
- `common-misconceptions` -> `Common Misconceptions`

The existing English `/method-boundaries` page is intentionally excluded because it already exists and is already publicly available.

## Review Required Before Import

Each page must receive human editorial review before any CMS draft import. Science-method pages also require science review and legal/compliance review before publication.

| Page | Editorial | Science | Legal/privacy | Operator approval |
| --- | --- | --- | --- | --- |
| Assessment Science | Required | Required | Required | Required |
| Item Design Notes | Required | Required | Required | Required |
| Reliability and Validity | Required | Required | Required | Required |
| Data Notes | Required | Not required by default | Required | Required |
| Common Misconceptions | Required | Required | Required | Required |

## Claims Kept Intentionally Conservative

- No reliability, validity, sample, norm, item-bank, or version numbers are invented.
- Missing evidence is expressed as `Unknown` or as unavailable in current public documentation.
- Assessment pages do not claim diagnostic, medical, therapeutic, hiring, admissions, or career-outcome authority.
- RIASEC is framed as career-interest and work-environment preference, not ability measurement.
- MBTI and type language is framed as preference-style reference, not identity.
- Big Five score language is framed as contextual tendency, not moral ranking.
- Data Notes avoids retention, deletion, response-time, and support-SLA promises.

## Page-Specific Review Notes

### Assessment Science

- Safe claims used: assessments are framed as structured self-observation and result interpretation, not mind reading or final judgment.
- Review focus: confirm model descriptions for MBTI, Big Five, and RIASEC match the approved zh-CN page and the existing English `/method-boundaries` tone.
- Evidence blocked: reliability values, validity evidence, sample size, norm group, reviewer name, item-bank version, and validation date.

### Item Design Notes

- Safe claims used: item design is described as translating constructs into answerable questions and reducing single-item overinterpretation.
- Review focus: confirm similar-item and reverse-scored-item wording does not imply a specific FermatMind item-bank implementation that has not been reviewed.
- Evidence blocked: item-bank structure, item count, versioning, item-review workflow, and validation materials.

### Reliability and Validity

- Safe claims used: reliability, validity, norms, and measurement error are explained as concepts without numeric proof claims.
- Review focus: verify definitions and examples with a science reviewer before public release.
- Evidence blocked: internal consistency values, test-retest values, validity coefficients, sample ranges, norm tables, and reviewer metadata.

### Data Notes

- Safe claims used: response data, result data, support data, and aggregate statistics are separated without creating retention or deletion promises.
- Review focus: legal/privacy review must confirm support workflow language, deletion-request language, analytics boundaries, and the relationship to formal privacy policy.
- Evidence blocked: retention period, deletion SLA, analytics provider list, subprocessors, payment processing scope, account deletion workflow, and data export workflow.

### Common Misconceptions

- Safe claims used: type, score, interest, advice, model-mixing, and professional-help boundaries are explained without attacking competitors.
- Review focus: confirm the order and framing match the approved zh-CN page and remain neutral across MBTI, Big Five, and RIASEC.
- Evidence blocked: any assertion that a model predicts career success, salary, relationship outcomes, hiring fit, health status, or life outcomes.

## Claims That Still Need Human Review

- Whether the phrase `current public documentation does not provide specific numbers` matches FermatMind's preferred external wording.
- Whether the internal links should point to canonical English slugs or locale-prefixed slugs after import.
- Whether `Data Notes` should keep the slug `/data-privacy` for English parity, or use a new English-visible slug while preserving route compatibility.
- Whether visible FAQ content should be enabled only as body content, or later promoted to FAQ schema after legal/science review.
- Whether SEO title and description drafts should be shortened for the CMS field limits used in production.

## Forbidden Publication Actions

Do not perform any of the following from this package alone:

- Do not import into CMS as public content.
- Do not set `published`, `is_public`, `publish_allowed`, or `claim_gate_status=passed`.
- Do not set `is_indexable=true`.
- Do not expose in sitemap, `llms.txt`, footer navigation, article recommendations, search submissions, or social distribution.
- Do not generate FAQ schema until the question and answer text has been reviewed.
- Do not replace backend/CMS authority with frontend fallback content.

## Minimum Approval Checklist

Before these assets can become CMS drafts:

- English editorial review completed.
- Terminology reviewed against the approved zh-CN source and the existing English `/method-boundaries` page.
- Science review completed for four methodology/science pages.
- Privacy/legal review completed for all pages, with extra attention to `Data Notes`.
- SEO title and description approved or revised.
- Internal link targets confirmed for English routes.
- Backend importer supports non-public `locale=en` ContentPage draft import.

Before these assets can become public:

- CMS record exists for each English page.
- CMS review state is approved.
- `publish_allowed=true`.
- `claim_gate_status=passed`.
- `is_public=true`.
- `is_indexable` remains false until SEO title and description are approved.
- Public API returns `200` for each English slug.
- Footer exposure is updated only after public API verification.
