# HELP-CONTENT-DRAFT-CONTACT-SUPPORT-DECISION-01

Decision: `DIRECT_EMAIL_SUPPORT_CONTACT_SELECTED_WITH_PUBLISH_STILL_BLOCKED`

This task records the support-contact decision for the Help service content train. It did not publish, deploy, mutate CMS data, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund flows, change payment-provider behavior, rewrite content, or claim Operator approval.

## Inputs

- Prior policy-owner artifact: `backend/docs/help/generated/help-content-draft-policy-owner-answers-01.v1.json`
- Prior blocker: `contact_support_route_and_support_contact`
- User decision on 2026-06-05: direct email contact support.
- Support email: `support@fermatmind.com`

## Contact Support Decision

| Field | Decision |
| --- | --- |
| Selected support mode | Email |
| Selected support target | `support@fermatmind.com` |
| Selected public canonical | `mailto:support@fermatmind.com` |
| Require new `/help/contact-support` route | No |
| Require `/support` route change in this PR | No |

The Help service draft train should treat direct email to `support@fermatmind.com` as the contact-support path. The missing `/help/contact-support` page is no longer a publish blocker for this train, provided later package/CMS revisions remove or replace any dependency on that route.

## Blocker Mapping

- `contact_support_route_and_support_contact`: resolved by selecting direct email support as the contact path, pending application to package/import/CMS drafts.
- `support_contact_first_class_field`: still open; first-class ContentPage support-contact authority should be added or verified before publish preflight.
- `faq_schema_publish_gate`: still open; visible FAQ and JSON-LD equality must be verified only after content/schema authority is finalized.

## Recommended Next PR Train

1. `HELP-CMS-SERVICE-FIELDS-01`
2. `HELP-CONTENT-DRAFT-POLICY-REVISION-APPLY-01`
3. `HELP-CONTENT-DRAFT-POLICY-CMS-SYNC-01`
4. `HELP-CONTENT-DRAFT-OPERATOR-REVIEW-R2-01`
5. `HELP-SERVICE-FAQ-SCHEMA-RUNTIME-01`
6. `HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-01`
7. `HELP-CONTENT-DRAFT-PUBLISH-EXECUTE-01`
8. `HELP-CONTENT-DRAFT-POST-PUBLISH-SMOKE-01`

## Remaining Hard Gates

- No publish without separate exact publish authorization.
- No Operator approval claimed by Codex.
- No CMS mutation in this task.
- First-class ContentPage `support_contact` remains unverified.
- Contact-support decision still needs to be applied to the local revised package/import artifact before CMS sync.
- FAQPage schema remains conditional until visible FAQ/JSON-LD equality is verified after content is finalized.

## Validation Commands

```bash
python3 -m json.tool backend/docs/help/generated/help-content-draft-contact-support-decision-01.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/docs/help/generated/help-content-draft-contact-support-decision-01.v1.json backend/docs/operations/help-content-draft-contact-support-decision-2026-06-05.md docs/codex/pr-train.yaml docs/codex/pr-train-state.json
git diff --cached --check
```
