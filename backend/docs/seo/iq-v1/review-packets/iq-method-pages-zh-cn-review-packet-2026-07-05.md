# IQ Method Pages zh-CN Review Packet

Packet: `IQ-METHOD-PAGES-ZH-CN-REVIEW-PACKET-2026-07-05`

Created at: `2026-07-05T14:09:30+08:00`

Reviewer: `Codex GPT-5`, acting as a FermatMind operator-authorized internal method and claim boundary reviewer.

This packet is not a clinical review, psychometric validation, legal approval, official certification, accreditation, or external expert endorsement. It does not authorize production writes, article publish, sitemap activation, `llms.txt` activation, search submission, or production deploy.

## Decision

Overall result: `pass_for_backend_publish_gate_only`

The 7 zh-CN IQ method pages may proceed to `IQ-METHOD-PAGES-ZH-CN-CMS-PUBLISH-GATE-01` as draft/noindex CMS Article candidates.

They remain blocked from public indexing and GEO surfaces until later gated steps pass:

- `IQ-METHOD-PAGES-ZH-CN-CMS-REVIEW-APPROVAL-01`
- `IQ-METHOD-PAGES-ZH-CN-CMS-PUBLISH-01`
- `IQ-METHOD-PAGES-ZH-CN-CMS-POST-PUBLISH-READBACK-01`
- `IQ-METHOD-PAGES-ZH-CN-SEO-GEO-ACTIVATE-GATE-01`
- `IQ-METHOD-PAGES-ZH-CN-SEO-GEO-ACTIVATE-01`

## Evidence

- Source package: `fap-web/generated/iq-method-pages-zh-cn-v0.2`
- Dry-run package PR: `fap-web` PR #1606, merge commit `297bd9c7809edc257555a57be5ae406d43e8c05f`
- Backend CMS import PR: `fap-api` PR #2721, merge commit `83eb4781082f17e574a575b09b92d23acc75021d`
- Backend CMS readback PR: `fap-api` PR #2724, merge commit `d73366e9467fab5ad3dcf2fb16e3d50520ed38ca`
- Readback evidence: real fap-web package imported 7 draft Articles into temp sqlite; readback returned `status=pass`, `mismatch_count=0`

## Global Claim Result

Status: `pass_with_contextual_boundary_mentions`

No forbidden claim was approved as a public promise.

Some restricted terms appear only in negative or guard contexts, for example:

- not used for admission, hiring, salary, or job decisions
- no answers or scoring rules are exposed
- no certificate/certification schema or media direction is allowed
- private result, order, payment, and recovery links stay out of public pages

These mentions are allowed as boundary language. They must not be converted into affirmative marketing claims.

## Required Public Boundaries

Every page must retain these boundaries:

- FermatMind IQ V1 is non-official, non-clinical, and non-certified.
- It is an online IQ-style reasoning task, not a formal IQ assessment.
- Product context may say original 30 questions and approximately 20 minutes.
- Results are limited to raw score, accuracy, completion time, and dimension-performance signals for this task.
- No IQ score, percentile, certified IQ, official IQ proof, Mensa eligibility, diagnosis, admission, hiring, salary, or career-outcome claim is allowed.
- No answer key, correct answer, item rule, solving step, scoring formula, private result link, payment/order/recovery link, private report field, or admin/preview URL may appear in public content.

## Page Review Table

| # | Slug | Method review | Claim review | Notes |
|---|---|---|---|---|
| 1 | `what-is-iq-style-reasoning-test` | Pass | Pass | Correctly defines IQ-style reasoning and frames V1 as original 30-question task feedback, not formal IQ measurement. |
| 2 | `online-iq-test-vs-professional-assessment` | Pass | Pass | Correctly distinguishes online self-guided testing from professional assessment using norms, supervision, errors, and professional interpretation. |
| 3 | `iq-test-score-meaning-boundary` | Pass | Pass | Limits result meaning to raw score, accuracy, completion time, dimension performance, and task stability signals. |
| 4 | `matrix-reasoning-pattern-recognition-guide` | Pass | Pass | Explains matrix and pattern reasoning at a high level without items, answers, solving steps, or scoring rules. |
| 5 | `why-fermatmind-iq-v1-not-certification` | Pass | Pass | Explains why V1 does not provide certification or official proof. |
| 6 | `iq-test-privacy-data-boundary` | Pass | Pass | Correctly separates public content, private test flow, private result pages, and backend scoring authority. |
| 7 | `iq-expert-review-disclosure` | Pass | Pass | Frames advisor/doctoral-team involvement as review support, not clinical certification or official endorsement. |

## Forbidden Claim Scan

Checked terms include:

- `官方 IQ`
- `认证 IQ`
- `真实智商`
- `智商证书`
- `PDF certificate`
- `certificate`
- `Mensa`
- `临床诊断`
- `诊断级`
- `录取`
- `招聘`
- `薪资预测`
- `职业判断`
- `固定智力`
- `percentile`
- `population percentile`
- `百分位`
- `IQ estimate`
- `answer_key`
- `correct_answer`

Per-page `claim_audit.json` files reported `forbidden_terms_found=[]`. Additional reviewer scan found only contextual boundary mentions, not affirmative forbidden claims.

## Approval Use

This packet may be used by:

- `IQ-METHOD-PAGES-ZH-CN-CMS-PUBLISH-GATE-01`
- `IQ-METHOD-PAGES-ZH-CN-CMS-REVIEW-APPROVAL-01`

It must not be used as:

- clinical certification
- official IQ certification
- external endorsement
- automatic production write approval
- automatic publish approval
- automatic sitemap or `llms.txt` activation approval

## Remaining Requirements

Before production mutation:

- backend publish readiness gate must pass
- exact Article IDs and revision IDs must be locked
- operator must approve the exact review packet and target revisions
- exact deployed SHA authorization must be given
- controlled review approval write must be dry-run first

Before sitemap or `llms.txt` activation:

- post-publish readback must pass
- canonical and robots must be verified on public URLs
- private route and scoring-token guards must pass
- separate SEO/GEO activation gate must pass
