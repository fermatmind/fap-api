# RIASEC Explanation V2 Reference, Media, and Revision Approval Gate

Task: `SEO-ARTICLE-RIASEC-V2-REFERENCE-MEDIA-REVISION-APPROVAL-01`

Decision: **NO-GO: operator inputs required before CMS draft approval preflight.**

This task did not rewrite article content, mutate CMS records, publish, submit search URLs, deploy, access private result/order/share/pay/payment/history URLs, read secrets, or claim operator approval.

## Current Draft State

| Locale | Article ID | Working revision | Status | Public | Indexable | Approval |
| --- | ---: | ---: | --- | --- | --- | --- |
| zh | 40 | 45 | draft | false | false | machine_draft / not approved |
| en | 41 | 46 | draft | false | false | machine_draft / not approved |

The drafts remain safe to keep unpublished. Public pages, sitemap exposure, llms exposure, search submission, and controlled publish remain blocked.

## Blocking Gates

| Gate | Status | Owner | Required input |
| --- | --- | --- | --- |
| Reference acceptance | blocked | editor or psychometrics reviewer | Accepted public source list, citation style, and source-to-claim acceptance. |
| CMS media | blocked | CMS operator or design owner | CMS Media Library cover image, alt review, and social image decision. |
| Revision approval | blocked | operator editor | Explicit approval for zh revision 45 and en revision 46 after source/media/claim checks. |
| Claim warning acknowledgement | blocked | editor or psychometrics reviewer | Acknowledge the 2 zh boundary-context warnings or request GPT revision. |
| Conditional internal links | blocked | SEO operator | Decide whether `/zh/career/jobs` and `/en/career/jobs` are eligible before publish. |
| Product availability | blocked | product operator | Confirm report-preview and product-availability statements against the live RIASEC module. |
| Controlled publish preflight | blocked | Codex after separate authorization | Run only after all prior gates pass. |

## Operator Input Checklist

Required before `SEO-ARTICLE-RIASEC-V2-CMS-DRAFT-APPROVAL-PREFLIGHT-01` can pass:

- `accepted_source_urls`: Unknown
- `accepted_source_titles`: Unknown
- `citation_style`: Unknown
- `holland_hexagon_terms_acceptance`: Unknown
- `mbti_big_five_comparison_acceptance`: Unknown
- `no_official_affiliation_acknowledgement`: Unknown
- `cms_media_id`: Unknown
- `cover_image_url`: Unknown
- `cover_image_alt_reviewed`: Unknown
- `og_image_ready`: Unknown
- `twitter_image_ready`: Unknown
- `approved_by`: Unknown
- `approved_at`: Unknown
- `approval_notes`: Unknown
- `claim_warning_acknowledgement`: Unknown
- `gpt_revision_required`: Unknown
- `conditional_links_activation_decision`: Unknown
- `product_availability_confirmed`: Unknown
- `report_preview_language_confirmed`: Unknown

## Sidecar Issues

- `RIASEC_V2_REFERENCE_ACCEPTANCE_REQUIRED`: accepted references and claim-to-source mapping are required before CMS draft approval preflight can pass.
- `RIASEC_V2_CMS_MEDIA_REQUIRED`: reviewed CMS Media Library cover image and alt decision are required before publish preflight can pass.
- `RIASEC_V2_REVISION_APPROVAL_REQUIRED`: zh/en working revisions remain machine_draft and require explicit operator approval or a GPT revision loop.
- `RIASEC_V2_PRODUCT_AVAILABILITY_CONFIRMATION_REQUIRED`: report-preview and product-availability statements remain Unknown until product confirmation.

## Next Gate

Recommended next task: `SEO-ARTICLE-RIASEC-V2-CMS-DRAFT-APPROVAL-PREFLIGHT-01`.

Expected state unless operator inputs are supplied: **NO-GO / blocked external operator input**.

No CMS mutation, publish, search submission, or deploy is authorized by this approval gate.
