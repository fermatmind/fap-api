# IQ V1 Next Exact Codex Execution Prompts

## 1. Result Page Safe Scoring QA

```text
/goal Run IQ-V1-RESULT-PAGE-SAFE-SCORING-QA-FIX-01 in /Users/rainie/Desktop/GitHub/fap-api.

Mode: implementation only if current main has a scoped regression; otherwise add/confirm focused tests and report FIX_ALREADY_EFFECTIVE.

Start from latest main. Do not touch CMS, DB, payment, deploy, production smoke, search submission, item-bank answers, scoring formulas, correct answers, item order, or images. Verify backend IQ result/report payloads do not expose formal intelligence credentials, norm-backed public score claims without authority, population ranking, downloadable credential claims, payment CTA/unlock copy, or answer-key/private scoring fields. Run focused tests only: IqReportContractTest, IqReportBuilderTest, IqOwnerOriginal30PrivateScoringTest, plus git diff --check. Open one scoped PR only if code or tests change.
```

## 2. Positioning Lock

```text
/goal Run IQ-V1-POSITIONING-LOCK-01 in /Users/rainie/Desktop/GitHub/fap-api.

Mode: implementation only if current main has a scoped regression; otherwise confirm POSITIONING_LOCKED and stop with evidence.

Start from latest main. Keep IQ V1 positioned as a free IQ-style reasoning test with 30 original items, about 20 minutes, raw score/correct rate/completion time/dimension performance, and free complete result. Block formal intelligence credentials, population ranking, clinical judgment, external high-IQ society affiliation, admission/hiring/salary/career claims, downloadable credential claims, and paid-report SEO claims. Do not write CMS, deploy, revalidate, submit to search, touch payment, or modify fap-web fallback copy. Run IqV1PositioningLockTest, LandingSurfacePublicApiTest focused IQ assertions, IqSeoRampAuthorityTest, and git diff --check. Open one scoped PR only if artifacts/tests change.
```

## 3. Landing Copy CMS Dry-Run

```text
/goal Run IQ-V1-LANDING-COPY-CMS-DRY-RUN-01 in /Users/rainie/Desktop/GitHub/fap-api.

Create a docs/generated-artifact-only PR for zh-CN and en IQ landing copy dry-run. Do not write CMS, create CMS drafts, publish, deploy, revalidate, submit to search, or touch the separate seven IQ method-page CMS dry-run. Every copy field must be draft_review_only. Cover title, meta, H1, hero, CTA, FAQ, boundary copy, free complete result copy, and internal links. Enforce the IQ V1 positioning lock and forbidden claim list. Mention seven method pages only as draft dependency links, not imports. Run focused claim-safety grep and git diff --check. Open one scoped PR.
```

## 4. Item Dimension Tagging

```text
/goal Run IQ-V1-ITEM-DIMENSION-TAGGING-IMPLEMENTATION-01 in /Users/rainie/Desktop/GitHub/fap-api.

Implement backend-owned metadata-only safe item dimension aliases for IQ_OWNER_ORIGINAL_30. Allowed aliases: matrix_reasoning, visual_reasoning, pattern_recognition, spatial_reasoning. Forbidden V1 aliases: working_memory, processing_speed, quantitative_reasoning, verbal_comprehension. Do not change answer keys, correct answers, scoring formulas, item order, images/SVGs, asset hashes, options, or runtime scoring outcomes. Add focused tests that all 30 public items have safe aliases, forbidden aliases are absent, public question payloads do not leak private scoring fields, and runtime scoring still passes. Run IqOwnerOriginal30PrivateScoringTest, the new focused tests, and git diff --check. Open one scoped PR.
```
