# IQ V1 Landing Copy CMS Dry Run

Status: `draft_review_only`
PR: `IQ-V1-LANDING-COPY-CMS-DRY-RUN-01`
CMS write allowed: `false`

## Purpose

This package prepares operator-review copy for the IQ V1 landing surface without creating CMS drafts, publishing content, revalidating caches, submitting search channels, or changing runtime behavior.

## Included Surfaces

- `zh-CN`: `/zh/tests/iq-test-intelligence-quotient-assessment`
- `en`: `/en/tests/iq-test-intelligence-quotient-assessment`

## Covered Fields

- title
- SEO title
- meta description
- H1
- hero title and subhead
- primary and secondary CTA
- question count
- duration
- result scope
- free complete result copy
- boundary copy
- FAQ
- internal links
- method-page dependency links
- claim policy
- claim-safety scan result

## Product Truth

- Free IQ-style reasoning practice.
- 30 original items.
- About 20 minutes.
- Result page shows raw score, accuracy, completion time, and dimension performance.
- V1 result is free.
- Beta-stage standard score may appear only when backend report authority supplies it with the proper boundary copy.

## Boundaries

This package does not claim a formal intelligence credential, population ranking, clinical judgment, external high-IQ society affiliation, school or hiring evidence, salary prediction, career outcome guarantee, downloadable credential, or paid report entitlement.

## Deferred Items

- CMS write.
- CMS draft creation.
- CMS publish.
- Production import.
- Production smoke.
- Deploy.
- Cache revalidation.
- Search channel submission.
- Seven IQ method-page CMS dry run.

## Acceptance

- Every public copy field is labeled `draft_review_only`.
- `cms_write_allowed` is `false`.
- No frontend fallback copy is introduced.
- Runtime authority remains backend CMS/public API after separate owner approval.
