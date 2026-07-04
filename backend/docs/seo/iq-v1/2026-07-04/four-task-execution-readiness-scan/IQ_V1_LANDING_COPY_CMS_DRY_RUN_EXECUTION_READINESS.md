# IQ V1 Landing Copy CMS Dry-Run Execution Readiness

## Classification

`READY_FOR_CMS_DRY_RUN_PR`

## Reasoning

Positioning lock is already present, result/report safety is effective, and landing-surface authority is backend/CMS-owned. A dry-run package can be generated safely if it remains docs/generated artifact only and labels all copy `draft_review_only`.

## Proposed Artifact Names

- `backend/docs/seo/iq-v1/landing-copy-cms-dry-run/iq-v1-landing-copy-cms-dry-run.v1.json`
- `backend/docs/seo/iq-v1/landing-copy-cms-dry-run/IQ_V1_LANDING_COPY_CMS_DRY_RUN.md`
- Optional validator output: `backend/docs/seo/iq-v1/landing-copy-cms-dry-run/IQ_V1_LANDING_COPY_CLAIM_SAFETY_CHECK.md`

## Exact Fields

For each locale (`zh-CN`, `en`):

- `surface_key`
- `locale`
- `test_slug`
- `canonical_path`
- `draft_status`
- `title`
- `seo_title`
- `meta_description`
- `h1`
- `hero_title`
- `hero_subhead`
- `primary_cta`
- `secondary_cta`
- `questions_label`
- `duration_label`
- `result_scope`
- `free_complete_result_copy`
- `boundary_copy`
- `faq`
- `internal_links`
- `method_page_links_draft_review_only`
- `claim_policy`
- `forbidden_claim_scan`
- `cms_write_allowed=false`

## Acceptance Checks

- All copy is labeled `draft_review_only`.
- No CMS write, draft creation, import, publish, revalidation, search submission, or production smoke.
- No claim of formal intelligence credentials, clinical interpretation, external high-IQ society affiliation, population ranking, admission/hiring/salary/career guarantees, downloadable credentials, or paid report SEO unlock.
- Explicitly states: free IQ-style reasoning test, 30 original items, about 20 minutes, raw score/correct rate/completion time/dimension performance, free complete result, and non-official/non-diagnostic boundary.
- Seven IQ method-page CMS dry-run links may be referenced as dependency candidates only; do not import or write those pages in this task.

## Exact Implementation Prompt

Run `IQ-V1-LANDING-COPY-CMS-DRY-RUN-01` as a docs/generated-artifact-only PR in `/Users/rainie/Desktop/GitHub/fap-api`. Start from latest main, do not write CMS, do not create drafts, do not publish, do not deploy, do not revalidate, do not submit to search channels, and do not touch the separate seven IQ method-page CMS dry-run. Generate the zh-CN/en landing-copy dry-run package with every copy field marked `draft_review_only`, enforce the IQ V1 positioning lock, run focused claim-safety grep plus `git diff --check`, commit, push, open one PR, and stop after PR creation/check status per repo policy.
