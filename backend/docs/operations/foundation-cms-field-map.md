# Foundation CMS Field Map

Date: 2026-06-05

PR train item: `FOUNDATION-CMS-FIELD-MAP-01`

Mode: documentation, generated artifact, and contract test only. This PR does not change the `content_pages` schema, mutate CMS records, create Foundation copy, create DailyGiving records, process proof files, publish, submit search URLs, or deploy.

## Decision

Foundation can be prepared through existing CMS `content_pages` fields, but the current schema does not contain dedicated governance fields for claim-boundary version, public-benefit policy version, proof-policy summary, or FAQ/schema gates.

The next content-production step should use existing fields for the page body and review state, while keeping missing governance fields explicit in the request card and operator checklist. Do not hide these requirements inside frontend fallback content.

## Existing `content_pages` Field Coverage

| Requirement | Existing field | Status |
| --- | --- | --- |
| public route identity | `slug`, `path`, `locale`, `canonical_path` | available |
| page classification | `kind`, `page_type`, `template`, `animation_profile` | available |
| visible page body | `title`, `kicker`, `summary`, `content_md`, `content_html`, `headings_json` | available |
| SEO metadata | `seo_title`, `meta_description`, `seo_description`, `canonical_path` | available |
| public/index state | `status`, `is_public`, `is_indexable`, `published_at`, `effective_at` | available |
| translation workflow | `translation_group_id`, `source_locale`, `translation_status`, `source_content_id`, `source_version_hash`, `translated_from_version_hash` | available |
| review workflow | `review_state`, `owner`, `legal_review_required`, `science_review_required`, `last_reviewed_at`, `source_doc`, `source_updated_at` | available |

## Required Foundation Mapping

| Foundation need | Map now | Required rule |
| --- | --- | --- |
| plan explanation modules | `content_md` or `content_html` | CMS/backend authority only |
| module headings | `headings_json` and visible body | no frontend editorial fallback |
| review owner | `owner` | required before publish |
| claim review state | `review_state`, `legal_review_required`, `last_reviewed_at` | approved before public expansion |
| route | `slug=foundation`, localized `path`, localized `locale` | canonical public route only |
| indexability | `is_indexable=true` only for Foundation | DailyGiving remains separate and noindex |
| source package trace | `source_doc` | point to internal asset/request-card reference, not public proof |
| SEO | `seo_title`, `meta_description`, `seo_description` | no final metadata in this PR |

## Explicit Missing Governance Fields

These are not dedicated `content_pages` columns today:

- `claim_boundary_version`
- `public_benefit_policy_version`
- `proof_policy_summary`
- `daily_giving_state_summary`
- `faq_items`
- `faq_schema_enabled`
- `faq_schema_review_state`
- `operator_review_checklist`

Until a later schema or metadata container exists, these requirements must remain in internal docs/request cards and must be operator-reviewed before CMS publish. They must not be approximated through frontend static content.

## DailyGiving Dependency Boundary

Foundation may reference DailyGiving only as a gated future ledger concept and record-review process. DailyGiving fields stay in `daily_giving_records`, not `content_pages`.

Relevant existing DailyGiving fields:

- `donation_status`
- `proof_status`
- `proof_public_url`
- `proof_private_path`
- `proof_redaction_notes`
- `receipt_reference_redacted`
- `receipt_reference_private`
- `public_notes`
- `internal_notes`
- `is_public`
- `is_indexable`
- `published_at`
- manual social URL fields

Foundation CMS content must not claim a public DailyGiving ledger, public proof, stable daily operation, or trust badge unless later release gates pass.

## GPT Input Implications

GPT should receive this field map before preparing content inputs. GPT should:

- request module-level CMS fields and review notes;
- preserve governance-field gaps as explicit operator requirements;
- keep FAQ as question inventory only unless CMS-approved answers exist;
- keep DailyGiving noindex and zero-public-record state visible in the evidence table;
- avoid final H1, metadata, FAQ answers, CTA, social copy, and trust badge copy.

## Codex Follow-up Implications

Codex may later add schema or metadata support only under a separate authorized PR. Until then, Codex should validate that Foundation content stays CMS-backed and that DailyGiving proof/record/index gates are not bypassed through Foundation page copy.
