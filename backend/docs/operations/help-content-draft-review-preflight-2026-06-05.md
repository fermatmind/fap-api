# HELP-CONTENT-DRAFT-REVIEW-PREFLIGHT-01

Decision: `REVIEW_PREFLIGHT_PASS_OPERATOR_REVIEW_REQUIRED_PUBLISH_BLOCKED`.

This PR prepares the Help service CMS draft editorial review handoff. It does not perform Operator editorial approval, publish, mutate CMS rows, deploy, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund flows, or change payment-provider behavior.

## Evidence

- Required uploaded zip exists at `/Users/rainie/Desktop/fermatmind-help-service-content-drafts-01.zip`.
- Zip SHA-256: `2e3a947b3b59663e6f359de0237a4efe4e7dc2ec518be93b3bda15ffeb0aaae6`.
- Zip contains 14 files: six markdown drafts, six YAML metadata files, `index.json`, and `README.md`.
- Source index says `publish_allowed=false` and `requires_operator_review=true`.
- Postcheck artifact: `backend/docs/help/generated/help-content-draft-postcheck-01.v1.json`.
- Postcheck decision: `POSTCHECK_PASS_WITH_PUBLISH_BLOCKED_SIDECARS`.
- Postcheck found 12 expected ContentPage draft rows across six help slugs and two locales.
- Postcheck confirmed draft rows are non-public, non-indexable, unpublished, and absent from public routes, sitemap, and llms surfaces.

## Operator Review Handoff Requirements

Operator review must record:

- reviewer
- review timestamp
- policy version
- per-asset decision
- required revisions, if any
- support-contact sidecar resolution
- confirmation that publish/search/deploy remain blocked until a separate explicit authorization

Allowed Operator outcomes:

- `approved_for_publish_preflight`
- `revisions_required`
- `rejected`

## Assets Requiring Review

- `UNLOCK-FAILURE-HELP-CARD-01`
- `PAYMENT-REFUND-FAQ-PACKAGE-01`
- `RESULT-RECOVERY-FAQ-01`
- `PRIVACY-FAQ-PACKAGE-01`
- `NONDIAGNOSTIC-HELP-COPY-01`
- `DATA-DELETION-REQUEST-FAQ-01`

## Sidecars

- `HELP-CONTENT-DRAFT-REVIEW-PREFLIGHT-SUPPORT-CONTACT-FIELD`: `support@fermatmind.com` exists in the import package, but not in production ContentPage rows and no first-class `support_contact` field was verified.
- `HELP-CONTENT-DRAFT-REVIEW-PREFLIGHT-OPERATOR-APPROVAL`: review handoff is ready, but Codex did not perform or assert Operator editorial approval.
- `HELP-CONTENT-DRAFT-REVIEW-PREFLIGHT-PUBLISH-BLOCK`: publish, indexability, sitemap/llms inclusion, and search submission remain forbidden without separate explicit approval.

## Next Task

`HELP-CONTENT-DRAFT-EDITORIAL-REVIEW-01`
