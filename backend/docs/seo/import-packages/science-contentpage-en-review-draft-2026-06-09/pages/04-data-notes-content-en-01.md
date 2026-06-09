---
page_key: DATA-NOTES-CONTENT-EN-01
source_zh_key: data-privacy
page_asset_key: data_privacy
locale: en
source_locale: zh-CN
en_title: Data Notes
proposed_slug: /data-privacy
fallback_slug_if_nested_route_not_supported: /data-privacy
page_type: privacy
kind: data_results_notes
review_state: owner_review
science_review_required: false
legal_review_required: true
legal_review_reason: This page discusses response data, result data, support workflows, deletion requests, and analytics boundaries, so privacy/legal review is required.
status: draft
publish_allowed: false
operator_approval_required: true
claim_gate_status: not_reviewed
faq_schema_eligible: false
is_public: false
is_indexable: false
sitemap_eligible: false
llms_eligible: false
footer_eligible: false
meta_title_draft: Data Notes
meta_description_draft: How FermatMind frames response data, result data, support data, aggregate analytics, deletion requests, and privacy boundaries.
internal_links_allowed:
  - /method-boundaries
  - /science
  - /common-misconceptions
---

## What Kind of Data This Page Explains

The FermatMind assessment experience may involve several types of data: user response data, result data generated from responses, service data used to support recovery or unlocking, and aggregate statistics used to observe public page performance.

These data types have different uses and different risks. The purpose of this page is to help users understand which information belongs to private results, which information is needed for product operation, and which information exists only as aggregate page-level observation.

## Response Data and Result Data

Response data means the choices a user makes in assessment items. Result data means the result summary, dimension explanation, or report content generated from those responses. This type of data is personal because it may reflect a user's self-description of personality, interests, behavioral tendencies, or career preferences.

Private results should not be used as public page material. They should not enter search engines, sitemaps, llms files, public article links, social links, or statistics pages. Any result link with a user-specific identifier should not be treated as public content.

## Support and Recovery Data

When a user needs to recover a result, resolve an unlock failure, request a refund, or handle an account issue, the system may need the minimum necessary information to locate the request. A safer approach is to start with email and use masked identifying information where possible, rather than asking the user to submit full order numbers, full payment identifiers, full result links, or screenshots containing private links on a public page.

If additional matching information is needed, the preferred approach should use the last few characters, a masked order code, or another system-defined safe identifier. Specific support fields and response timelines should follow the formal Help pages and support rules. Unconfirmed information should remain Unknown.

## Aggregate Statistics Are Different From Personal Results

Public pages may use aggregate statistics to observe product experience, such as how often a public page is visited, whether a public test entry is clicked, or whether a flow has errors. These statistics are used for product improvement and should not be understood as public personal assessment results.

Aggregate statistics should not record private result pages, order pages, payment pages, history pages, or user-specific links. If these paths appear in analytics tools, the privacy issue should be handled before growth analysis continues.

## Data Retention, Deletion, and Account Handling

Whether users can request deletion, which data can be deleted, how long processing takes, and whether results can still be recovered afterward must be confirmed by formal privacy policies and support workflows. If current public documentation does not provide a specific processing period, it should remain Unknown. The page should not promise immediate deletion or permanent non-retention without reviewed authority.

If a user requests deletion, identity should be verified through a safe channel. Public comments, social-media direct messages, or unsafe forms should not be the primary method for handling private results or order issues.

## The Boundary of This Page

This page is not a complete legal privacy policy and does not replace formal terms. It explains the basic boundaries of assessment data inside the product. Before publication, privacy/legal review should confirm data retention, deletion requests, support paths, and analytics boundaries.

visible_faq_items:
Can my assessment result be seen by search engines?

It should not be. Private results and user-specific links should not enter sitemaps, llms files, search submissions, or public internal links.

Does support need my full order number?

A public page should not ask for a full order number. A safer approach is email-first support plus masked identifying information or a system-defined safe identifier.

Can analytics tools see my result?

Analytics tools should not record private result pages or order pages. They should only observe public pages and aggregate behavior.

Can I delete my assessment data?

You can make a request. The exact scope, identity-verification method, and processing time should follow the formal privacy and support workflow; unconfirmed fields should remain Unknown.

Can I recover a result after deletion?

That depends on the deletion scope and system handling. Current public documentation does not provide one universal answer, so formal support rules need to confirm it.
