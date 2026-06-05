# HELP-CONTENT-DRAFT-POLICY-OWNER-ANSWERS-01

Decision: `POLICY_OWNER_ANSWERS_RECORDED_WITH_PUBLISH_STILL_BLOCKED`

This task records policy-owner answers for the Help service content train. It did not publish, deploy, mutate CMS data, submit search URLs, access private result/order/share/pay/payment/history URLs, read secrets/env/cookies/tokens, run payment/refund flows, change payment-provider behavior, rewrite content, or claim Operator approval.

## Inputs

- Prior review artifact: `backend/docs/help/generated/help-content-draft-operator-review-01.v1.json`
- Prior review decision: `REVIEW_BLOCKED_POLICY_OWNER_INPUTS_REQUIRED`
- Revised v01 zip: `/Users/rainie/Desktop/费马资料文件/fermatmind-help-service-content-drafts-01.zip`
- Revised v01 SHA-256: `f971f5cd279018c2db469ccd87c43484c4983de5484e8c1e47343aa5813e6bb9`
- Policy owner answers provided by the user on 2026-06-05.

## Recorded Policy Owner Answers

| Field | Answer | Follow-up interpretation |
| --- | --- | --- |
| Refund exclusions | 非“费马测试”原因 | Exclude refund requests for reasons not caused by FermatMind/Fermat test service. |
| Refund handling time | 三个工作日 | Handle refund requests within three business days. |
| Data deletion handling time | 两年 | Record as provided by policy owner; apply only in a later authorized content/CMS task. |
| Retained-data exceptions after deletion | 无 | No retained-data exceptions after completed deletion. |
| Privacy analytics wording confirmation | 正确 | GA4, Baidu analytics, and first-party analytics privacy wording is confirmed as correct by the policy owner. |

## Blocker Mapping

- `refund_policy_owner_fields`: answered, but not yet applied to the local package or CMS drafts.
- `data_deletion_policy_owner_fields`: answered, but not yet applied to the local package or CMS drafts.
- `privacy_analytics_owner_confirmation`: answered, but not yet applied to the relevant draft metadata/content.
- `contact_support_route_and_support_contact`: still open; this PR does not decide `/help/contact-support` versus the existing public canonical `/support`.
- `faq_schema_publish_gate`: still open; visible FAQ to JSON-LD equality must be verified only after policy fields are applied and public visibility is authorized.

## Recommended PR Train

1. `HELP-CONTENT-DRAFT-CONTACT-SUPPORT-DECISION-01`
2. `HELP-CMS-SERVICE-FIELDS-01`
3. `HELP-CONTENT-DRAFT-POLICY-REVISION-APPLY-01`
4. `HELP-CONTENT-DRAFT-POLICY-CMS-SYNC-01`
5. `HELP-CONTENT-DRAFT-OPERATOR-REVIEW-R2-01`
6. `HELP-SERVICE-FAQ-SCHEMA-RUNTIME-01`
7. `HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-01`
8. `HELP-CONTENT-DRAFT-PUBLISH-EXECUTE-01`
9. `HELP-CONTENT-DRAFT-POST-PUBLISH-SMOKE-01`

Only `HELP-CONTENT-DRAFT-POLICY-OWNER-ANSWERS-01` is added to the manifest/state in this PR. The follow-up PR ids above still require separate manifest/state authorization before execution.

## Remaining Hard Gates

- No publish without separate exact publish authorization.
- No Operator approval claimed by Codex.
- No CMS mutation in this task.
- Contact-support route handling remains unresolved.
- First-class ContentPage `support_contact` remains unverified.
- FAQPage schema remains conditional until visible FAQ/JSON-LD equality is verified after content is finalized.

## Validation Commands

```bash
shasum -a 256 /Users/rainie/Desktop/费马资料文件/fermatmind-help-service-content-drafts-01.zip
python3 -m json.tool backend/docs/help/generated/help-content-draft-policy-owner-answers-01.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 -c "import yaml, pathlib; yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text()); print('yaml ok')"
git diff --check -- backend/docs/help/generated/help-content-draft-policy-owner-answers-01.v1.json backend/docs/operations/help-content-draft-policy-owner-answers-2026-06-05.md docs/codex/pr-train.yaml docs/codex/pr-train-state.json
git diff --cached --check
```
