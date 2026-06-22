# PR Train State Legacy Reconcile Audit - 2026-06-22

## Scope
This is a read-only audit of `docs/codex/pr-train-state.json` against GitHub PR state. It does not modify the ledger, runtime code, content assets, CMS state, or production gates.

## Method
- Parsed top-level object entries from `docs/codex/pr-train-state.json`.
- Queried GitHub PR state with `gh pr view` for entries whose ledger state was not `merged`, plus `merged` entries with missing closeout fields.
- Classified entries by GitHub merge truth, ledger status semantics, and closeout field completeness.

## Summary
| Metric | Count |
| --- | --- |
| Top-level task entries | 282 |
| Queried GitHub PRs | 124 |
| GitHub MERGED but ledger status is not merged | 82 |
| Merged-like, sidecar, failed, blocked, or semantic terminal statuses | 28 |
| status=merged but missing closeout fields | 42 |
| Suggested next safe ledger-only reconcile candidates | 14 |
| Needs individual confirmation before automated reconcile | 40 |

## 1. GitHub MERGED But Ledger Status Is Not `merged`
These entries currently have a non-`merged` ledger status while GitHub reports the PR as `MERGED`.

| id | ledger_status | repo | PR | gh_merge | gh_merged_at | ledger_commit | ledger_merged_at | remote_deleted | local_cleanup |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| ANALYTICS-FUNNEL-CONTROLLED-REFRESH-WRITE-GUARD-01 | ci_fix_local_checks_passed | fermatmind/fap-api | 1783 | 4e3b3aa5fc | 2026-05-31T07:41:39Z | 9604f3f5 |  | False | False |
| ANALYTICS-FUNNEL-EVENT-TAXONOMY-01 | ci_fix_local_checks_passed | fermatmind/fap-api | 1761 | 699605b00f | 2026-05-30T21:45:27Z | 265e1dcc58 |  | False | False |
| ANALYTICS-FUNNEL-GA4-BAIDU-MAPPING-SCAN-01 | ready_to_merge | fermatmind/fap-api | 1850 | d176ddc421 | 2026-06-02T03:52:41Z | d176ddc4 | 2026-06-02T00:52:00Z | True | True |
| ANALYTICS-FUNNEL-OPS-READ-MODEL-REPAIR-01 | ci_fix_local_checks_passed | fermatmind/fap-api | 1764 | 59189f38f6 | 2026-05-30T22:30:25Z | 10bdb0eb10 |  | False | False |
| ANALYTICS-FUNNEL-REFRESH-DRY-RUN-SQL-FIX-01 | pr_open_checks_pending | fermatmind/fap-api | 1772 | 7a7bf813f6 | 2026-05-31T01:28:49Z | 31374f79d9 |  | False | False |
| ANALYTICS-FUNNEL-REFRESH-DRY-RUN-SQL-FIX-02 | merged_deployed_reconciled | fermatmind/fap-api | 1775 | 2ef86f3bb4 | 2026-05-31T04:27:30Z | 2ef86f3bb4 | 2026-05-31T04:27:30Z | True | True |
| ANALYTICS-FUNNEL-REPORT-READY-MERGE-TIMESTAMP-03 | local_checks_passed_with_sidecar | fermatmind/fap-api | 1850 | d176ddc421 | 2026-06-02T03:52:41Z | pending_re |  | False | False |
| ANALYTICS-FUNNEL-REPORT-READY-PROJECTION-MAPPING-02 | merged_reconciled | fermatmind/fap-api | 1842 | c28f04a1f1 | 2026-06-01T13:39:50Z | c28f04a1f1 | 2026-06-01T13:39:50Z | True | False |
| ARTICLE-CTA-ROUTE-GATE-01 | pr_open | fermatmind/fap-api | 1897 | ff12145afc | 2026-06-05T01:41:29Z | d16e14083c | 2026-06-05T01:41:29Z | True | True |
| ARTICLE-FAQ-SCHEMA-SMOKE-01 | pr_open | fermatmind/fap-api | 1899 | 73d99a26b4 | 2026-06-05T01:49:36Z | ac4ee0d138 | 2026-06-05T01:49:36Z | True | True |
| ARTICLE-H1-02 | ready_to_merge | fermatmind/fap-api | 2014 | 8a3abf353e | 2026-06-09T06:20:25Z | 0f18583d13 |  | False | False |
| ARTICLE-H1-04 | ready_to_merge | fermatmind/fap-api | 2015 | 559ce5938d | 2026-06-09T07:09:53Z | a8e300f908 |  | False | False |
| ARTICLE-INTERNAL-LINK-PLAN-01 | pr_open | fermatmind/fap-api | 1895 | 6e0c78e7b2 | 2026-06-05T01:32:19Z | cd51c29ce0 | 2026-06-05T01:32:19Z | True | True |
| AUDIT-SEC-CONTENT-IMPORT-SAFETY-01 | merged_and_cleaned | fermatmind/fap-api | 1803 | 42a8caacb4 | 2026-05-31T14:31:03Z |  | 2026-05-31T14:31:03Z | True | True |
| AUDIT-SEC-CONTENT-PUBLISH-GATES-01 | merged_and_cleaned | fermatmind/fap-api | 1796 | 5bc34fbb8c | 2026-05-31T08:54:02Z |  | 2026-05-31T08:54:02Z | True | True |
| AUDIT-SEC-CONTENT-RUNTIME-CACHE-01 | merged_and_cleaned | fermatmind/fap-api | 1800 | 445fc2e91c | 2026-05-31T13:09:40Z |  | 2026-05-31T13:09:40Z | True | True |
| AUDIT-SEC-PAYMENT-INTEGRITY-01 | merged_and_cleaned | fermatmind/fap-api | 1793 | 14c3068390 | 2026-05-31T08:31:32Z | 1b4663df4c | 2026-05-31T08:31:32Z | True | True |
| AUDIT-SEC-RELEASE-TRAIN-01 | merged_and_cleaned | fermatmind/fap-api | 1780 | 2edb9e2fcc | 2026-05-31T06:52:45Z |  | 2026-05-31T06:52:45Z | True | True |
| AUDIT-SEC-RESULT-IDENTITY-01 | merged_and_cleaned | fermatmind/fap-api | 1786 | 9e59c976a7 | 2026-05-31T07:57:19Z |  | 2026-05-31T07:57:19Z | True | True |
| B5-RESULT-AUTO-CHECK-POLLER-01 | pr_open | fermatmind/fap-api | 2269 | e531e55f65 | 2026-06-22T02:49:19Z | e531e55f65 | 2026-06-22T02:49:19Z | True | True |
| B5-RESULT-AUTO-MERGE-LIVE-PILOT-01 | pr_open | fermatmind/fap-api | 2272 | d5562a8764 | 2026-06-22T03:26:57Z | 377d6430cf |  | False | False |
| B5-RESULT-M8-PRODUCTION-OPS-01 | ready_to_merge | fermatmind/fap-api | 2232 | bb6c0abb67 | 2026-06-21T16:43:41Z | bb6c0abb67 | 2026-06-21T16:43:41Z | True | True |
| B5-RESULT-MECHANICAL-FIX-APPLY-01 | pr_open | fermatmind/fap-api | 2271 | 04fd256956 | 2026-06-22T03:09:21Z | 04fd256956 | 2026-06-22T03:09:21Z | True | True |
| B5-RESULT-WEEKLY-OPS-RUNNER-01 | ready_to_merge | fermatmind/fap-api | 2256 | dae8d706db | 2026-06-22T00:46:16Z | 6f22501aba |  | False | False |
| CAREER-1046-OPS-SCOPE-RECONCILIATION-01 | completed | fermatmind/fap-api | 1754 | e30f96f4e0 | 2026-05-29T08:54:05Z | e30f96f4e0 | 2026-05-29T08:54:05Z | True | True |
| CAREER-DIRECTORY-10K-OPS-WARM-VALIDATE-01 | open_checks_passed | fermatmind/fap-api | 1835 | 9257a57f6d | 2026-06-01T06:37:11Z | e1a66823fa |  | False | False |
| CAREER-DIRECTORY-AUTHORITY-DRIFT-GATE-01 | pr_open_checks_pending | fermatmind/fap-api | 1846 | aa5b54b395 | 2026-06-01T17:16:27Z | pending_re |  | False | False |
| CAREER-FULL-PARITY-09 | ready_to_merge | fermatmind/fap-api | 2050 | 46e2300877 | 2026-06-11T18:56:20Z | edda0cb21a |  | False | False |
| CAREER-FULL-PARITY-10 | pending_dependency | fermatmind/fap-api | 2084 | b94c909b04 | 2026-06-13T18:50:42Z | 2a2f3053c0 |  | False | False |
| CAREER-SEARCH-CHANNEL-READINESS-GATE-01 | ready_to_merge | fermatmind/fap-api | 1847 | 5ef46feea5 | 2026-06-01T18:13:57Z | 5ef46feea5 | 2026-06-01T18:13:57Z | True | True |
| CAREER-ZH-DISPLAY-PARITY-04 | ready_to_merge | fermatmind/fap-api | 2023 | 4c61376eb8 | 2026-06-09T17:07:59Z | da07e91896 |  | False | False |
| DAILY-GIVING-FIRST-RECORD-REVIEW-TEMPLATE-01 | ready_to_merge | fermatmind/fap-api | 1920 | 79faa898df | 2026-06-05T08:31:53Z | 79faa898df | 2026-06-05T08:31:53Z | True | True |
| DAILY-GIVING-PROOF-REDACTION-SOP-01 | pr_open | fermatmind/fap-api | 1910 | 4a75168e5e | 2026-06-05T03:08:32Z | 4a75168e5e | 2026-06-05T03:08:32Z | True | True |
| DAILY-GIVING-REDACTED-PUBLIC-PROOF-01 | ready_to_merge | fermatmind/fap-api | 1932 | 7fa42da0d0 | 2026-06-05T12:57:57Z | 0ff71b5c39 |  | False | False |
| ENNEAGRAM-FC144-EN-QUESTIONS-PACK-03 | github_checks_passed | fermatmind/fap-api | 1736 | 4fa7e5a232 | 2026-05-27T17:04:27Z | 0b23d1c7b1 |  | False | False |
| FOUNDATION-CMS-FIELD-MAP-01 | pr_open | fermatmind/fap-api | 1905 | 624782eb52 | 2026-06-05T02:37:48Z | 624782eb52 | 2026-06-05T02:37:48Z | True | True |
| FOUNDATION-CONTENT-REQUEST-CARD-01 | pr_open | fermatmind/fap-api | 1904 | 8c772dc1d0 | 2026-06-05T02:22:52Z | 8c772dc1d0 | 2026-06-05T02:22:52Z | True | True |
| FOUNDATION-FAQ-SCHEMA-GATE-01 | pr_open | fermatmind/fap-api | 1907 | 05e1952f81 | 2026-06-05T02:52:42Z | 05e1952f81 | 2026-06-05T02:52:42Z | True | True |
| FREE-FULL-REPORT-MODE-FEATURE-FLAG-01 | pr_open | fermatmind/fap-api | 2223 | 1c0fcbbe9d | 2026-06-21T15:15:16Z |  |  | False | False |
| FREEMIUM-LOCALE-POLICY-01 | ci_fix_local_passed | fermatmind/fap-api | 1886 | 635106328e | 2026-06-04T11:18:58Z | b2d17375fc | 2026-06-05T04:28:00Z | True | True |
| GLOBAL-EN-ZH-CONTENT-PAGES-CMS-DRAFT-UPDATE-01 | github_checks_passed | fermatmind/fap-api | 1716 | 0a57dbf2ef | 2026-05-27T03:16:01Z | 8be84d8e67 |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-CMS-IMPORT-01 | github_checks_passed | fermatmind/fap-api | 1709 | a0895313fc | 2026-05-27T00:10:05Z | a1ff7cc1ef |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-PUBLISH-01 | pr_open_blocked_missing_official_publish_runtime | fermatmind/fap-api | 1718 | 1a46d72c24 | 2026-05-27T04:43:13Z | 6b2fb19c68 |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-HUMAN-REVISION-01 | github_checks_passed | fermatmind/fap-api | 1714 | 3255e661b6 | 2026-05-27T01:53:51Z | 12c9e34db8 |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-IMPORT-VERIFY-01 | github_checks_passed | fermatmind/fap-api | 1710 | 949489ed94 | 2026-05-27T00:40:19Z | b534cb6d5b |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-PUBLISH-READINESS-01 | github_checks_passed | fermatmind/fap-api | 1713 | b820f2b74e | 2026-05-27T01:20:41Z | 5376cc9c31 |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-PUBLISH-READINESS-R2 | github_checks_passed | fermatmind/fap-api | 1717 | 56c5db2328 | 2026-05-27T04:17:22Z | 3422f4d8c4 |  | False | False |
| OPS-API-INTERNAL-RESOLVE-PROOF | pr_opened_with_sidecars | fermatmind/fap-api | 1840 | aaed5216d1 | 2026-06-01T12:33:51Z | 93556a55fb |  | False | False |
| OPS-CAREER-WARM-CACHE-PERF-01 | ci_fix_validated | fermatmind/fap-api | 1776 | 85ab91f1ea | 2026-05-31T04:47:09Z | e7736fa0a9 |  | None | None |
| OPS-CI-CODESCAN-API-02 | github_checks_passed | fermatmind/fap-api | 1607 | 76106bfa88 | 2026-05-23T09:03:46Z | a194a0bb1d |  | False | False |
| OPS-RELEASE-TRAIN-CAREER-WARM-CACHE-NONBLOCKING-01 | validated | fermatmind/fap-api | 1779 | 59e24b8c50 | 2026-05-31T06:46:04Z | 4071ffe255 |  | False | False |
| PAYMENT-ALIPAY-OWNER-MISMATCH-CONTROLLED-REPAIR-04 | pr_opened_with_sidecar | fermatmind/fap-api | 1815 | 7a36b2b84b | 2026-05-31T16:25:12Z | a547cf12 |  | False | False |
| PAYMENT-ALIPAY-OWNER-MISMATCH-REVIEW-03 | github_checks_passed | fermatmind/fap-api | 1777 | 23dd1bc4c5 | 2026-05-31T05:45:19Z | 341d53c0e0 |  | False | False |
| PAYMENT-ALIPAY-PENDING-COMPENSATION-SCHEDULER-01 | github_checks_failed_fix_prepared | fermatmind/fap-api | 1766 | 0f30fac8c2 | 2026-05-30T23:31:40Z | d265ad3542 |  | False | False |
| PERSONALITY-COMPARISON-PAGES-01 | pr_open | fermatmind/fap-api | 2089 | 7b6ee96b4f | 2026-06-13T21:14:53Z | c3e8eea155 |  | False | False |
| PERSONALITY-PUBLIC-ASSET-CONTRACT-01 | ready_to_merge | fermatmind/fap-api | 2079 | e7b8d85a8d | 2026-06-13T17:08:34Z | 0f88273b48 |  | False | False |
| PERSONALITY-SEO-TITLE-METADATA-01 | ready_to_merge | fermatmind/fap-api | 2087 | 1b90745492 | 2026-06-13T20:22:14Z | 1b90745492 | 2026-06-13T20:22:14Z | True | True |
| PR-FDN-02A-POST-DEPLOY-RUNTIME-VALIDATION | pr_open_checks_pending | fermatmind/fap-api | 1833 | 3a7e29175f | 2026-06-01T04:39:30Z | 322bf0d8f0 |  | False | False |
| PR-FDN-SOCIAL-SYNC-READINESS | ci_fix_validated_with_sidecar | fermatmind/fap-api | 1819 | 25e47f6b7d | 2026-05-31T16:38:25Z | 25e47f6b7d | 2026-05-31T16:38:25Z | True | True |
| PR-HIRING-01-POST-PUBLISH-SMOKE | merged_with_sidecar | fermatmind/fap-api | 1839 | 02a0c610b5 | 2026-06-01T12:12:39Z | 92d4ae4d8f | 2026-06-01T12:12:39Z | True | True |
| PR-POL-01-LEDGER-RECONCILE-AND-SMOKE | merged_with_sidecar | fermatmind/fap-api | 1838 | 49427b78cf | 2026-06-01T11:55:15Z | 9453a65a2a | 2026-06-01T19:55:15+08:00 | True | False |
| RIASEC-FULL-CONTENT-PACK-02A | github_checks_passed | fermatmind/fap-api | 1465 | 1335414931 | 2026-05-18T14:43:04Z | 827fd3f2fd | 2026-05-31T13:43:00+08:00 | True | True |
| RIASEC-FULL-CONTENT-PACK-13-BE | pr_opened_github_checks_pending | fermatmind/fap-api | 1511 | 6a02044c9f | 2026-05-20T03:28:26Z | 471187ff |  | False | False |
| SEARCH-CHANNEL-LIVE-00 | github_checks_failed | fermatmind/fap-api | 1720 | 9d9004bab3 | 2026-05-27T06:28:18Z | 401099d481 | 2026-05-20T13:08:36Z | True | True |
| SEARCH-CHANNEL-LIVE-01-PREFLIGHT | github_checks_passed | fermatmind/fap-api | 1775 | 2ef86f3bb4 | 2026-05-31T04:27:30Z | 96363c8404 |  | False | False |
| SEO-ARTICLE-PUBLISH-HOLD-GATE-01 | pr_open | fermatmind/fap-api | 1902 | 36ff1e3561 | 2026-06-05T01:57:23Z | bea93807c4 |  | False | False |
| SEO-CMS-CANARY-PREFLIGHT-LEDGER-01 | merged_external_dependency | fermatmind/fap-web | 1004 | 52247a6487 | 2026-06-02T14:01:06Z | 52247a6487 | 2026-06-02T14:01:06Z | None | None |
| SEO-CMS-CANARY-PREVIEW-01 | conditional_not_required_for_current_canary_cms_only_accepted | fermatmind/fap-api | 2136 | f1b5bd96e2 | 2026-06-19T21:04:19Z | 11e82c0c70 |  | False | False |
| SEO-CONTENT-P1-08 | draft_created_postcheck_passed_publish_blocked_editorial_review_next | fermatmind/fap-api | 1873 | 4fa540738e | 2026-06-03T14:19:44Z | be48917d18 |  | None | None |
| SEO-CONTENT-P1-09-PUBLISH-PREFLIGHT-01 | merged_then_controlled_publish_completed | fermatmind/fap-api | 1866 | 5dc9f8e9fa | 2026-06-03T04:34:28Z | 5dc9f8e9fa | 2026-06-03T04:34:28Z | True | True |
| SEO-CONV-DAILY-04 | github_checks_passed_ready_to_merge | fermatmind/fap-api | 2011 | 1cae5d854b | 2026-06-09T04:18:01Z | 21b6fc6d23 | 2026-06-09T04:18:01Z | True | True |
| SEO-CONV-OPS-05 | github_checks_passed_ready_to_merge | fermatmind/fap-api | 2012 | 15ae74b9e2 | 2026-06-09T04:48:10Z | 23c33d0191 |  | False | False |
| SEO-DASH-04B | github_checks_passed | fermatmind/fap-api | 1443 | 40af10a702 | 2026-05-17T10:44:19Z | 2727135d1d |  | None | None |
| SEO-DASH-COLLECTOR-01-SMOKE-RECONCILE | ready_to_merge | fermatmind/fap-api | 1882 | 1b88323eb0 | 2026-06-04T01:40:56Z | 1b88323eb0 | 2026-06-04T01:38:00Z | True | True |
| SEO-DASH-COLLECTOR-02 | pr_open | fermatmind/fap-api | 1883 | 949b623976 | 2026-06-04T02:38:54Z | bfebe0a1d1 |  | False | False |
| SEO-REVIEW-P1-10 | merged_sitemap_llms_full_not_converged | fermatmind/fap-api | 1867 | c97974641c | 2026-06-03T05:03:23Z | c97974641c | 2026-06-03T05:03:23Z | True | True |
| SEO-REVIEW-P1-10-SITEMAP-LLMS-ENUMERATION-01 | merged_no_go_search_submission | fermatmind/fap-api | 1868 | 8da3405f75 | 2026-06-03T05:21:44Z | 8da3405f75 | 2026-06-03T05:21:44Z | True | True |
| SEO-SEARCH-SUBMISSION-BAIDU-MANUAL-ZH-CANARY-01 | merged_then_baidu_zh_submission_confirmed_by_follow_up | fermatmind/fap-api | 1870 | 85ba119344 | 2026-06-03T11:16:57Z | 85ba119344 | 2026-06-03T11:16:57Z | True | True |
| SEO-SEARCH-SUBMISSION-BAIDU-MANUAL-ZH-CANARY-CONFIRM-01 | merged_baidu_zh_submission_confirmed | fermatmind/fap-api | 1871 | 39d75a476b | 2026-06-03T11:37:09Z | 39d75a476b | 2026-06-03T11:37:09Z | True | True |
| SEO-SEARCH-SUBMISSION-CANARY-POSTSUBMIT-SIGNALS-01 | pr_open_postsubmit_signals_reviewed | fermatmind/fap-api | 1872 | cb4c680895 | 2026-06-03T11:53:20Z | 0836478273 |  | False | False |
| SEO-SEARCH-SUBMISSION-GSC-SITEMAP-CANARY-01 | merged_gsc_sitemap_submitted | fermatmind/fap-api | 1869 | e7ed36c49a | 2026-06-03T07:33:15Z | e7ed36c49a | 2026-06-03T07:33:15Z | True | True |
| SEO-SITEMAP-STABILITY-02 | github_checks_passed_pending_external_merge | fermatmind/fap-api | 2007 | ac2af02543 | 2026-06-08T14:54:11Z | 302e4171f9 |  | False | False |

## 2. Merged-Like Custom Statuses
These entries appear to encode extra business, sidecar, failure-recovery, blocked, or terminal workflow semantics in the status. Do not mechanically flatten them unless the team decides that `status=merged` plus separate notes/checks should carry those semantics.

| id | ledger_status | repo | PR | gh_merge | gh_merged_at | recommendation |
| --- | --- | --- | --- | --- | --- | --- |
| ANALYTICS-FUNNEL-REFRESH-DRY-RUN-SQL-FIX-02 | merged_deployed_reconciled | fermatmind/fap-api | 1775 | 2ef86f3bb4 | 2026-05-31T04:27:30Z | preserve_or_review_semantics |
| ANALYTICS-FUNNEL-REPORT-READY-MERGE-TIMESTAMP-03 | local_checks_passed_with_sidecar | fermatmind/fap-api | 1850 | d176ddc421 | 2026-06-02T03:52:41Z | preserve_or_review_semantics |
| ANALYTICS-FUNNEL-REPORT-READY-PROJECTION-MAPPING-02 | merged_reconciled | fermatmind/fap-api | 1842 | c28f04a1f1 | 2026-06-01T13:39:50Z | preserve_or_review_semantics |
| AUDIT-SEC-CONTENT-IMPORT-SAFETY-01 | merged_and_cleaned | fermatmind/fap-api | 1803 | 42a8caacb4 | 2026-05-31T14:31:03Z | preserve_or_review_semantics |
| AUDIT-SEC-CONTENT-PUBLISH-GATES-01 | merged_and_cleaned | fermatmind/fap-api | 1796 | 5bc34fbb8c | 2026-05-31T08:54:02Z | preserve_or_review_semantics |
| AUDIT-SEC-CONTENT-RUNTIME-CACHE-01 | merged_and_cleaned | fermatmind/fap-api | 1800 | 445fc2e91c | 2026-05-31T13:09:40Z | preserve_or_review_semantics |
| AUDIT-SEC-PAYMENT-INTEGRITY-01 | merged_and_cleaned | fermatmind/fap-api | 1793 | 14c3068390 | 2026-05-31T08:31:32Z | preserve_or_review_semantics |
| AUDIT-SEC-RELEASE-TRAIN-01 | merged_and_cleaned | fermatmind/fap-api | 1780 | 2edb9e2fcc | 2026-05-31T06:52:45Z | preserve_or_review_semantics |
| AUDIT-SEC-RESULT-IDENTITY-01 | merged_and_cleaned | fermatmind/fap-api | 1786 | 9e59c976a7 | 2026-05-31T07:57:19Z | preserve_or_review_semantics |
| CAREER-1046-OPS-SCOPE-RECONCILIATION-01 | completed | fermatmind/fap-api | 1754 | e30f96f4e0 | 2026-05-29T08:54:05Z | preserve_or_review_semantics |
| CAREER-FULL-PARITY-10 | pending_dependency | fermatmind/fap-api | 2084 | b94c909b04 | 2026-06-13T18:50:42Z | preserve_or_review_semantics |
| GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-PUBLISH-01 | pr_open_blocked_missing_official_publish_runtime | fermatmind/fap-api | 1718 | 1a46d72c24 | 2026-05-27T04:43:13Z | preserve_or_review_semantics |
| OPS-API-INTERNAL-RESOLVE-PROOF | pr_opened_with_sidecars | fermatmind/fap-api | 1840 | aaed5216d1 | 2026-06-01T12:33:51Z | preserve_or_review_semantics |
| PAYMENT-ALIPAY-OWNER-MISMATCH-CONTROLLED-REPAIR-04 | pr_opened_with_sidecar | fermatmind/fap-api | 1815 | 7a36b2b84b | 2026-05-31T16:25:12Z | preserve_or_review_semantics |
| PAYMENT-ALIPAY-PENDING-COMPENSATION-SCHEDULER-01 | github_checks_failed_fix_prepared | fermatmind/fap-api | 1766 | 0f30fac8c2 | 2026-05-30T23:31:40Z | preserve_or_review_semantics |
| PR-FDN-SOCIAL-SYNC-READINESS | ci_fix_validated_with_sidecar | fermatmind/fap-api | 1819 | 25e47f6b7d | 2026-05-31T16:38:25Z | preserve_or_review_semantics |
| PR-HIRING-01-POST-PUBLISH-SMOKE | merged_with_sidecar | fermatmind/fap-api | 1839 | 02a0c610b5 | 2026-06-01T12:12:39Z | preserve_or_review_semantics |
| PR-POL-01-LEDGER-RECONCILE-AND-SMOKE | merged_with_sidecar | fermatmind/fap-api | 1838 | 49427b78cf | 2026-06-01T11:55:15Z | preserve_or_review_semantics |
| SEARCH-CHANNEL-LIVE-00 | github_checks_failed | fermatmind/fap-api | 1720 | 9d9004bab3 | 2026-05-27T06:28:18Z | preserve_or_review_semantics |
| SEO-CMS-CANARY-PREFLIGHT-LEDGER-01 | merged_external_dependency | fermatmind/fap-web | 1004 | 52247a6487 | 2026-06-02T14:01:06Z | preserve_or_review_semantics |
| SEO-CMS-CANARY-PREVIEW-01 | conditional_not_required_for_current_canary_cms_only_accepted | fermatmind/fap-api | 2136 | f1b5bd96e2 | 2026-06-19T21:04:19Z | preserve_or_review_semantics |
| SEO-CONTENT-P1-08 | draft_created_postcheck_passed_publish_blocked_editorial_review_next | fermatmind/fap-api | 1873 | 4fa540738e | 2026-06-03T14:19:44Z | preserve_or_review_semantics |
| SEO-CONTENT-P1-09-PUBLISH-PREFLIGHT-01 | merged_then_controlled_publish_completed | fermatmind/fap-api | 1866 | 5dc9f8e9fa | 2026-06-03T04:34:28Z | preserve_or_review_semantics |
| SEO-REVIEW-P1-10 | merged_sitemap_llms_full_not_converged | fermatmind/fap-api | 1867 | c97974641c | 2026-06-03T05:03:23Z | preserve_or_review_semantics |
| SEO-REVIEW-P1-10-SITEMAP-LLMS-ENUMERATION-01 | merged_no_go_search_submission | fermatmind/fap-api | 1868 | 8da3405f75 | 2026-06-03T05:21:44Z | preserve_or_review_semantics |
| SEO-SEARCH-SUBMISSION-BAIDU-MANUAL-ZH-CANARY-01 | merged_then_baidu_zh_submission_confirmed_by_follow_up | fermatmind/fap-api | 1870 | 85ba119344 | 2026-06-03T11:16:57Z | preserve_or_review_semantics |
| SEO-SEARCH-SUBMISSION-BAIDU-MANUAL-ZH-CANARY-CONFIRM-01 | merged_baidu_zh_submission_confirmed | fermatmind/fap-api | 1871 | 39d75a476b | 2026-06-03T11:37:09Z | preserve_or_review_semantics |
| SEO-SEARCH-SUBMISSION-GSC-SITEMAP-CANARY-01 | merged_gsc_sitemap_submitted | fermatmind/fap-api | 1869 | e7ed36c49a | 2026-06-03T07:33:15Z | preserve_or_review_semantics |

## 3. `status=merged` But Missing Closeout Fields
These entries already say `merged`, but at least one closeout field is incomplete. They need a separate ledger-only closeout pass or an explicit decision to tolerate older incomplete records.

| id | repo | PR | missing_fields | gh_merge | gh_merged_at | ledger_commit | ledger_merged_at | remote_deleted | local_cleanup |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| ANALYTICS-FUNNEL-OPS-ORG0-VISIBILITY-REPAIR-01 | fermatmind/fap-api | 1815 | merged_at,remote_branch_deleted,local_cleanup_executed | 7a36b2b84b | 2026-05-31T16:25:12Z | c1d818f6df |  | False | False |
| ANALYTICS-FUNNEL-PURCHASE-REPORT-TRUTH-BRIDGE-03 | fermatmind/fap-api | 1855 | merged_at,remote_branch_deleted,local_cleanup_executed | 88efaf9b6e | 2026-06-02T14:26:58Z | c3ffcee3a5 |  | False | False |
| ANALYTICS-PAYMENT-UNLOCK-ATTRIBUTION-REPAIR-01A | fermatmind/fap-api | 1844 | merged_at,remote_branch_deleted,local_cleanup_executed | 02a45894d4 | 2026-06-01T16:42:48Z | c7d8b38be1 |  | False | False |
| AUDIT-CAREER-DETAIL-READY-1048-SCAN-1 | fermatmind/fap-api | 1724 | local_cleanup_executed | 6e576fcf89 | 2026-05-27T08:51:26Z | 9d7f67d2da | 2026-05-27T16:45:00+08:00 | True | False |
| AUDIT-SEC-TRAIN-LEDGER-CLOSEOUT-01 | fermatmind/fap-api | 1804 | commit_sha,merged_at,remote_branch_deleted,local_cleanup_executed | 68a36d7fd2 | 2026-05-31T14:42:04Z |  |  | False | False |
| CAREER-10K-ROLLOUT-ARCHITECTURE-SPEC-01 | fermatmind/fap-api | 1848 | merged_at,remote_branch_deleted,local_cleanup_executed | 0cd32b6609 | 2026-06-01T18:27:43Z | 8662cf8e92 |  | False | False |
| CAREER-ZH-PARITY-LIVE-01 | fermatmind/fap-api | 2033 | local_cleanup_executed | 88a8ad133e | 2026-06-11T10:33:40Z | aafff42fb5 | 2026-06-11T10:33:40Z | True | False |
| CONTENT-PACKS-CI-ARTIFACT-CONSUMER-SPLIT-01 | fermatmind/fap-api | 1768 | merged_at,remote_branch_deleted,local_cleanup_executed | 0dfa38ba0c | 2026-05-30T23:11:55Z | f42f301798 |  | False | False |
| CONTENT-PACKS-INDEX-ARTIFACT-01 | fermatmind/fap-api | 1763 | merged_at,remote_branch_deleted,local_cleanup_executed | 12a66f8c08 | 2026-05-30T21:57:30Z | 7e0cc715e0 |  | False | False |
| DETAIL_READY_1046_DELTA_AUTHORITY_REPAIR-01 | fermatmind/fap-api | 1747 | local_cleanup_executed | 6716bf50c3 | 2026-05-28T13:53:15Z | 6716bf50c3 | 2026-05-28T13:53:15Z | True | False |
| DETAIL_READY_1046_ROLLOUT_APPLY_PREFLIGHT-01 | fermatmind/fap-api | 1748 | merged_at,remote_branch_deleted,local_cleanup_executed | 1e04fd01e7 | 2026-05-28T14:27:10Z | 67e95aac7a |  | False | False |
| DETAIL_READY_1046_ROLLOUT_NO_AUDIT_DRY_RUN-01 | fermatmind/fap-api | 1749 | merged_at,remote_branch_deleted,local_cleanup_executed | f0dc07d19c | 2026-05-28T15:13:19Z | 09ec7f599b |  | False | False |
| DETAIL_READY_1047_DELTA_AUTHORITY_REPAIR-01 | fermatmind/fap-api | 1746 | local_cleanup_executed | e5cbc6816d | 2026-05-28T13:23:11Z | e5cbc6816d | 2026-05-28T13:23:11Z | True | False |
| DETAIL_READY_1048_REPLACEMENT_AUTHORITY_INDEX_STATE_CONFLICT-01 | fermatmind/fap-api | 1745 | local_cleanup_executed | 05f7dda0b1 | 2026-05-28T12:59:55Z | 05f7dda0b1 | 2026-05-28T12:59:55Z | True | False |
| DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_CONTROLLED_IMPORT-01 | fermatmind/fap-api | 1744 | merged_at,remote_branch_deleted,local_cleanup_executed | 16c01f81b6 | 2026-05-28T11:43:47Z | 39cf711566 |  | False | False |
| DETAIL_READY_1048_REPLACEMENT_AUTHORITY_SOURCE_REPAIR-01 | fermatmind/fap-api | 1743 | remote_branch_deleted,local_cleanup_executed | 54b47ccc27 | 2026-05-28T11:09:41Z | c1544e2510 | 2026-05-28T11:09:41Z | False | False |
| EMAIL-OUTBOX-SCHEDULER-BOOTSTRAP-04 | fermatmind/fap-api | 1809 | merged_at,remote_branch_deleted,local_cleanup_executed | d6e76bb622 | 2026-05-31T15:25:35Z | c240dd9468 |  | False | False |
| FERMAT-MARKETING-SKILLS-ADAPTATION-01 | fermatmind/fap-api | 1682 | merged_at,remote_branch_deleted,local_cleanup_executed | 92fca6a2fb | 2026-05-25T13:21:27Z | 2b9608e72d |  | False | False |
| FERMAT-MARKETING-SKILLS-ADAPTATION-02 | fermatmind/fap-api | 1684 | merged_at,remote_branch_deleted,local_cleanup_executed | fb3f681e1e | 2026-05-25T13:51:16Z | e2d9c14e41 |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-PUBLISH-RUNTIME-01 | fermatmind/fap-api | 1719 | local_cleanup_executed | 76a326807b | 2026-05-27T05:22:00Z | 76a326807b | 2026-05-27T05:22:00Z | True | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-HUMAN-REVISION-01A-FOUNDATION-GOVERNANCE-FACT-RECONCILE | fermatmind/fap-api | 1715 | merged_at,remote_branch_deleted,local_cleanup_executed | 9441524cee | 2026-05-27T02:39:11Z | a320b69289 |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-HUMAN-REVISION-R2 | fermatmind/fap-api | 1721 | merged_at,remote_branch_deleted,local_cleanup_executed | 47b99b657d | 2026-05-27T07:01:13Z | d62ad4eeef |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-POST-PUBLISH-SMOKE-01 | fermatmind/fap-api | 1723 | merged_at,remote_branch_deleted,local_cleanup_executed | adaeb13347 | 2026-05-27T08:03:16Z | ec1960bc10 |  | False | False |
| HELP-CONTENT-DRAFT-PUBLISH-PREFLIGHT-R2-01 | fermatmind/fap-api | 2001 | local_cleanup_executed | bf937f6754 | 2026-06-08T10:01:19Z | bf937f6754 | 2026-06-08T10:01:19Z | True | False |
| HELP-CONTENT-PAGES-CONTROLLED-PUBLISH-RUNTIME-01 | fermatmind/fap-api | 2000 | local_cleanup_executed | 31f0ce4f9f | 2026-06-08T09:48:48Z | 31f0ce4f9f | 2026-06-08T09:48:48Z | True | False |
| MARKETINGSKILLS-FERMATMIND-FIT-SCAN-00 | fermatmind/fap-api | 1680 | merged_at,remote_branch_deleted,local_cleanup_executed | 295abacb8a | 2026-05-25T12:41:38Z | 2d08bb4655 |  | False | False |
| OPS-CI-CODESCAN-API-01 | fermatmind/fap-api | 1476 | merged_at,remote_branch_deleted,local_cleanup_executed | 267de7e75e | 2026-05-19T03:50:09Z | 962be45f81 |  | False | False |
| OPS-RELEASE-TRAIN-BACKEND-DEPLOY-ADAPTER-01 | fermatmind/fap-api | 1770 | merged_at,remote_branch_deleted,local_cleanup_executed | 1832765a88 | 2026-05-31T00:32:34Z | 81a620782c |  | False | False |
| OPS-RELEASE-TRAIN-WORKFLOW-PRODUCTION-ENV-BINDING-01 | fermatmind/fap-api | 1771 | merged_at,remote_branch_deleted,local_cleanup_executed | 3f56a9f143 | 2026-05-31T01:21:01Z | c17da937 |  | False | False |
| OPS-RELEASE-WORKFLOW-01 | fermatmind/fap-api | 1478 | merged_at,remote_branch_deleted,local_cleanup_executed | f04fe2048c | 2026-05-19T04:19:29Z | 2084a3a5e9 |  | False | False |
| PAYMENT-ALIPAY-RETURN-IMMEDIATE-COMPENSATION-05 | fermatmind/fap-api | 1834 | merged_at,remote_branch_deleted,local_cleanup_executed | 49a8f814d6 | 2026-06-01T05:02:28Z | a843d4f1 |  | False | False |
| PAYMENT-ALIPAY-SCHEDULER-BOOTSTRAP-02 | fermatmind/fap-api | 1774 | merged_at,remote_branch_deleted,local_cleanup_executed | 498363c648 | 2026-05-31T01:48:42Z | fecc207643 |  | False | False |
| PR-FDN-SOCIAL-SYNC-MVP-01 | fermatmind/fap-api | 1829 | merged_at,remote_branch_deleted,local_cleanup_executed | 2e4a6429a4 | 2026-06-01T03:24:21Z | pending_re |  | False | False |
| PR-POL-01 | fermatmind/fap-api | 1769 | merged_at,remote_branch_deleted,local_cleanup_executed | d66249fb74 | 2026-05-30T23:21:04Z | 99245b30 |  | False | False |
| REPAIR-CAREER-DETAIL-READY-CANDIDATE-PREP-1 | fermatmind/fap-api | 1726 | local_cleanup_executed | cbbbbb8504 | 2026-05-27T10:37:37Z | cbbbbb8504 | 2026-05-27T10:37:37Z | True | False |
| REPAIR-CAREER-DETAIL-READY-LIVE-ACCEPTANCE-CLOSEOUT-1 | fermatmind/fap-api | 1730 | merged_at,remote_branch_deleted,local_cleanup_executed | b8fc4683ef | 2026-05-27T13:21:58Z | 0241144257 |  | False | False |
| REPAIR-CAREER-DETAIL-READY-RUNTIME-ARTIFACT-REFRESH-1 | fermatmind/fap-api | 1728 | local_cleanup_executed | 147fab4ebc | 2026-05-27T12:15:24Z | 147fab4ebc | 2026-05-27T12:15:24Z | True | False |
| REPAIR-CAREER-DETAIL-READY-TARGET-AUTHORITY-1 | fermatmind/fap-api | 1725 | local_cleanup_executed | b55c3668fc | 2026-05-27T09:27:15Z | b55c3668fc | 2026-05-27T17:32:30+08:00 | True | False |
| RESULT-EN-PARITY-06 | fermatmind/fap-api | 1675 | merged_at,remote_branch_deleted,local_cleanup_executed | b5a7e1bf8c | 2026-05-25T07:23:57Z | d137d244fd |  | False | False |
| SEO-CONV-INGEST-02 | fermatmind/fap-api | 2010 | merged_at,remote_branch_deleted,local_cleanup_executed | db2541da0a | 2026-06-09T03:22:41Z | 70266977b8 |  | False | False |
| SUPPLY-CHAIN-SYMFONY-AUDIT-UNBLOCK-20260527 | fermatmind/fap-api | 1727 | local_cleanup_executed | c1b708fc0d | 2026-05-27T10:21:24Z | c1b708fc0d | 2026-05-27T10:21:24Z | True | False |
| TAKE-EN-QUESTION-LOCALE-SCAN-00 | fermatmind/fap-api | 1732 | merged_at,remote_branch_deleted,local_cleanup_executed | 6daeca160e | 2026-05-27T15:27:51Z | 75b26a54aa |  | False | False |

## 4. Suggested Next Safe Ledger-Only Reconcile Batch
These are good candidates for the next mechanical ledger-only PR because GitHub reports `MERGED`, a merge commit and merge timestamp are available, branch cleanup is already recorded, and the ledger status is a stale operational state rather than a semantic status.

| id | current_status | repo | PR | gh_merge | gh_merged_at | suggested_action |
| --- | --- | --- | --- | --- | --- | --- |
| ARTICLE-CTA-ROUTE-GATE-01 | pr_open | fermatmind/fap-api | 1897 | ff12145afc | 2026-06-05T01:41:29Z | status->merged; align merge commit/time from GitHub |
| ARTICLE-FAQ-SCHEMA-SMOKE-01 | pr_open | fermatmind/fap-api | 1899 | 73d99a26b4 | 2026-06-05T01:49:36Z | status->merged; align merge commit/time from GitHub |
| ARTICLE-INTERNAL-LINK-PLAN-01 | pr_open | fermatmind/fap-api | 1895 | 6e0c78e7b2 | 2026-06-05T01:32:19Z | status->merged; align merge commit/time from GitHub |
| B5-RESULT-AUTO-CHECK-POLLER-01 | pr_open | fermatmind/fap-api | 2269 | e531e55f65 | 2026-06-22T02:49:19Z | status->merged; align merge commit/time from GitHub |
| B5-RESULT-M8-PRODUCTION-OPS-01 | ready_to_merge | fermatmind/fap-api | 2232 | bb6c0abb67 | 2026-06-21T16:43:41Z | status->merged; align merge commit/time from GitHub |
| B5-RESULT-MECHANICAL-FIX-APPLY-01 | pr_open | fermatmind/fap-api | 2271 | 04fd256956 | 2026-06-22T03:09:21Z | status->merged; align merge commit/time from GitHub |
| CAREER-SEARCH-CHANNEL-READINESS-GATE-01 | ready_to_merge | fermatmind/fap-api | 1847 | 5ef46feea5 | 2026-06-01T18:13:57Z | status->merged; align merge commit/time from GitHub |
| DAILY-GIVING-FIRST-RECORD-REVIEW-TEMPLATE-01 | ready_to_merge | fermatmind/fap-api | 1920 | 79faa898df | 2026-06-05T08:31:53Z | status->merged; align merge commit/time from GitHub |
| DAILY-GIVING-PROOF-REDACTION-SOP-01 | pr_open | fermatmind/fap-api | 1910 | 4a75168e5e | 2026-06-05T03:08:32Z | status->merged; align merge commit/time from GitHub |
| FOUNDATION-CMS-FIELD-MAP-01 | pr_open | fermatmind/fap-api | 1905 | 624782eb52 | 2026-06-05T02:37:48Z | status->merged; align merge commit/time from GitHub |
| FOUNDATION-CONTENT-REQUEST-CARD-01 | pr_open | fermatmind/fap-api | 1904 | 8c772dc1d0 | 2026-06-05T02:22:52Z | status->merged; align merge commit/time from GitHub |
| FOUNDATION-FAQ-SCHEMA-GATE-01 | pr_open | fermatmind/fap-api | 1907 | 05e1952f81 | 2026-06-05T02:52:42Z | status->merged; align merge commit/time from GitHub |
| PERSONALITY-SEO-TITLE-METADATA-01 | ready_to_merge | fermatmind/fap-api | 2087 | 1b90745492 | 2026-06-13T20:22:14Z | status->merged; align merge commit/time from GitHub |
| SEO-CONV-DAILY-04 | github_checks_passed_ready_to_merge | fermatmind/fap-api | 2011 | 1cae5d854b | 2026-06-09T04:18:01Z | status->merged; align merge commit/time from GitHub |

## Needs Individual Confirmation
These are GitHub-merged entries where the ledger has stale or incomplete closeout data, but the existing fields are too weak, mismatched, or semantically loaded for a fully mechanical update without a targeted check.

| id | ledger_status | repo | PR | gh_merge | gh_merged_at | ledger_commit | ledger_merged_at | remote_deleted | local_cleanup |
| --- | --- | --- | --- | --- | --- | --- | --- | --- | --- |
| ANALYTICS-FUNNEL-CONTROLLED-REFRESH-WRITE-GUARD-01 | ci_fix_local_checks_passed | fermatmind/fap-api | 1783 | 4e3b3aa5fc | 2026-05-31T07:41:39Z | 9604f3f5 |  | False | False |
| ANALYTICS-FUNNEL-EVENT-TAXONOMY-01 | ci_fix_local_checks_passed | fermatmind/fap-api | 1761 | 699605b00f | 2026-05-30T21:45:27Z | 265e1dcc58 |  | False | False |
| ANALYTICS-FUNNEL-GA4-BAIDU-MAPPING-SCAN-01 | ready_to_merge | fermatmind/fap-api | 1850 | d176ddc421 | 2026-06-02T03:52:41Z | d176ddc4 | 2026-06-02T00:52:00Z | True | True |
| ANALYTICS-FUNNEL-OPS-READ-MODEL-REPAIR-01 | ci_fix_local_checks_passed | fermatmind/fap-api | 1764 | 59189f38f6 | 2026-05-30T22:30:25Z | 10bdb0eb10 |  | False | False |
| ANALYTICS-FUNNEL-REFRESH-DRY-RUN-SQL-FIX-01 | pr_open_checks_pending | fermatmind/fap-api | 1772 | 7a7bf813f6 | 2026-05-31T01:28:49Z | 31374f79d9 |  | False | False |
| ARTICLE-H1-02 | ready_to_merge | fermatmind/fap-api | 2014 | 8a3abf353e | 2026-06-09T06:20:25Z | 0f18583d13 |  | False | False |
| ARTICLE-H1-04 | ready_to_merge | fermatmind/fap-api | 2015 | 559ce5938d | 2026-06-09T07:09:53Z | a8e300f908 |  | False | False |
| B5-RESULT-AUTO-MERGE-LIVE-PILOT-01 | pr_open | fermatmind/fap-api | 2272 | d5562a8764 | 2026-06-22T03:26:57Z | 377d6430cf |  | False | False |
| B5-RESULT-WEEKLY-OPS-RUNNER-01 | ready_to_merge | fermatmind/fap-api | 2256 | dae8d706db | 2026-06-22T00:46:16Z | 6f22501aba |  | False | False |
| CAREER-DIRECTORY-10K-OPS-WARM-VALIDATE-01 | open_checks_passed | fermatmind/fap-api | 1835 | 9257a57f6d | 2026-06-01T06:37:11Z | e1a66823fa |  | False | False |
| CAREER-DIRECTORY-AUTHORITY-DRIFT-GATE-01 | pr_open_checks_pending | fermatmind/fap-api | 1846 | aa5b54b395 | 2026-06-01T17:16:27Z | pending_re |  | False | False |
| CAREER-FULL-PARITY-09 | ready_to_merge | fermatmind/fap-api | 2050 | 46e2300877 | 2026-06-11T18:56:20Z | edda0cb21a |  | False | False |
| CAREER-ZH-DISPLAY-PARITY-04 | ready_to_merge | fermatmind/fap-api | 2023 | 4c61376eb8 | 2026-06-09T17:07:59Z | da07e91896 |  | False | False |
| DAILY-GIVING-REDACTED-PUBLIC-PROOF-01 | ready_to_merge | fermatmind/fap-api | 1932 | 7fa42da0d0 | 2026-06-05T12:57:57Z | 0ff71b5c39 |  | False | False |
| ENNEAGRAM-FC144-EN-QUESTIONS-PACK-03 | github_checks_passed | fermatmind/fap-api | 1736 | 4fa7e5a232 | 2026-05-27T17:04:27Z | 0b23d1c7b1 |  | False | False |
| FREE-FULL-REPORT-MODE-FEATURE-FLAG-01 | pr_open | fermatmind/fap-api | 2223 | 1c0fcbbe9d | 2026-06-21T15:15:16Z |  |  | False | False |
| FREEMIUM-LOCALE-POLICY-01 | ci_fix_local_passed | fermatmind/fap-api | 1886 | 635106328e | 2026-06-04T11:18:58Z | b2d17375fc | 2026-06-05T04:28:00Z | True | True |
| GLOBAL-EN-ZH-CONTENT-PAGES-CMS-DRAFT-UPDATE-01 | github_checks_passed | fermatmind/fap-api | 1716 | 0a57dbf2ef | 2026-05-27T03:16:01Z | 8be84d8e67 |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-CONTROLLED-CMS-IMPORT-01 | github_checks_passed | fermatmind/fap-api | 1709 | a0895313fc | 2026-05-27T00:10:05Z | a1ff7cc1ef |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-HUMAN-REVISION-01 | github_checks_passed | fermatmind/fap-api | 1714 | 3255e661b6 | 2026-05-27T01:53:51Z | 12c9e34db8 |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-IMPORT-VERIFY-01 | github_checks_passed | fermatmind/fap-api | 1710 | 949489ed94 | 2026-05-27T00:40:19Z | b534cb6d5b |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-PUBLISH-READINESS-01 | github_checks_passed | fermatmind/fap-api | 1713 | b820f2b74e | 2026-05-27T01:20:41Z | 5376cc9c31 |  | False | False |
| GLOBAL-EN-ZH-CONTENT-PAGES-PUBLISH-READINESS-R2 | github_checks_passed | fermatmind/fap-api | 1717 | 56c5db2328 | 2026-05-27T04:17:22Z | 3422f4d8c4 |  | False | False |
| OPS-CAREER-WARM-CACHE-PERF-01 | ci_fix_validated | fermatmind/fap-api | 1776 | 85ab91f1ea | 2026-05-31T04:47:09Z | e7736fa0a9 |  | None | None |
| OPS-CI-CODESCAN-API-02 | github_checks_passed | fermatmind/fap-api | 1607 | 76106bfa88 | 2026-05-23T09:03:46Z | a194a0bb1d |  | False | False |
| OPS-RELEASE-TRAIN-CAREER-WARM-CACHE-NONBLOCKING-01 | validated | fermatmind/fap-api | 1779 | 59e24b8c50 | 2026-05-31T06:46:04Z | 4071ffe255 |  | False | False |
| PAYMENT-ALIPAY-OWNER-MISMATCH-REVIEW-03 | github_checks_passed | fermatmind/fap-api | 1777 | 23dd1bc4c5 | 2026-05-31T05:45:19Z | 341d53c0e0 |  | False | False |
| PERSONALITY-COMPARISON-PAGES-01 | pr_open | fermatmind/fap-api | 2089 | 7b6ee96b4f | 2026-06-13T21:14:53Z | c3e8eea155 |  | False | False |
| PERSONALITY-PUBLIC-ASSET-CONTRACT-01 | ready_to_merge | fermatmind/fap-api | 2079 | e7b8d85a8d | 2026-06-13T17:08:34Z | 0f88273b48 |  | False | False |
| PR-FDN-02A-POST-DEPLOY-RUNTIME-VALIDATION | pr_open_checks_pending | fermatmind/fap-api | 1833 | 3a7e29175f | 2026-06-01T04:39:30Z | 322bf0d8f0 |  | False | False |
| RIASEC-FULL-CONTENT-PACK-02A | github_checks_passed | fermatmind/fap-api | 1465 | 1335414931 | 2026-05-18T14:43:04Z | 827fd3f2fd | 2026-05-31T13:43:00+08:00 | True | True |
| RIASEC-FULL-CONTENT-PACK-13-BE | pr_opened_github_checks_pending | fermatmind/fap-api | 1511 | 6a02044c9f | 2026-05-20T03:28:26Z | 471187ff |  | False | False |
| SEARCH-CHANNEL-LIVE-01-PREFLIGHT | github_checks_passed | fermatmind/fap-api | 1775 | 2ef86f3bb4 | 2026-05-31T04:27:30Z | 96363c8404 |  | False | False |
| SEO-ARTICLE-PUBLISH-HOLD-GATE-01 | pr_open | fermatmind/fap-api | 1902 | 36ff1e3561 | 2026-06-05T01:57:23Z | bea93807c4 |  | False | False |
| SEO-CONV-OPS-05 | github_checks_passed_ready_to_merge | fermatmind/fap-api | 2012 | 15ae74b9e2 | 2026-06-09T04:48:10Z | 23c33d0191 |  | False | False |
| SEO-DASH-04B | github_checks_passed | fermatmind/fap-api | 1443 | 40af10a702 | 2026-05-17T10:44:19Z | 2727135d1d |  | None | None |
| SEO-DASH-COLLECTOR-01-SMOKE-RECONCILE | ready_to_merge | fermatmind/fap-api | 1882 | 1b88323eb0 | 2026-06-04T01:40:56Z | 1b88323eb0 | 2026-06-04T01:38:00Z | True | True |
| SEO-DASH-COLLECTOR-02 | pr_open | fermatmind/fap-api | 1883 | 949b623976 | 2026-06-04T02:38:54Z | bfebe0a1d1 |  | False | False |
| SEO-SEARCH-SUBMISSION-CANARY-POSTSUBMIT-SIGNALS-01 | pr_open_postsubmit_signals_reviewed | fermatmind/fap-api | 1872 | cb4c680895 | 2026-06-03T11:53:20Z | 0836478273 |  | False | False |
| SEO-SITEMAP-STABILITY-02 | github_checks_passed_pending_external_merge | fermatmind/fap-api | 2007 | ac2af02543 | 2026-06-08T14:54:11Z | 302e4171f9 |  | False | False |

## Proposed Follow-Up
Open one or more ledger-only reconciliation PRs. Start with the safe batch above, keep scope limited to `docs/codex/pr-train-state.json`, and avoid changing custom merged-like statuses until their semantics are either preserved in a separate field or explicitly standardized.

## Negative Guarantees
- No runtime code was changed.
- No content assets were changed.
- No CMS import or write was performed.
- No production gate or rollout setting was changed.
