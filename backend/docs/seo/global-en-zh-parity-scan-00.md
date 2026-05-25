# GLOBAL-EN-ZH-PARITY-SCAN-00 Report

## 1. Executive Summary
The master scan completed in read-only mode against `https://fermatmind.com` after the deployed backend SHA `04a100f10b95023e160d362de29a297c7c937908` and frontend SHA `b14e1b038f838ffbcafd02ee1935291b213d6948`. Core home, MBTI, RIASEC, and MBTI topic pages returned 200 with expected canonicals/robots, and the core H1 overflow checks remain fixed.

The scan found active P0 discoverability defects: sitemap still exposes 18 content/help/policy URLs that return hard 404, and sitemap exposes 87 career job detail URLs while sampled job detail URLs return hard 404 after slow responses. `/en/support` and `/zh/support` also appear in llms surfaces while returning 404. No production mutation, CMS mutation, deploy, Search Channel action, URL submission, external search API call, or production user data access occurred.

Final decision: `global_en_zh_parity_scan_completed_ready_for_p0_fix_train`.

## 2. Scan Method / Lanes
Lanes A-J were executed with bounded runtime HTTP inventory, fap-api artifact inspection, fap-web reference-only source grep, production sitemap/llms observation, staging containment checks, RESULT/EN-PARITY artifact aggregation, and partial Playwright core visual checks. Runtime URL limit was bounded at 160 observed URLs; Playwright broader representative pass was attempted but reduced to core H1 checks after timeouts.

## 3. Runtime Revision Verification
Backend deployed SHA target: `04a100f10b95023e160d362de29a297c7c937908`. Local fap-api scan worktree SHA: `04a100f10b95023e160d362de29a297c7c937908`. Frontend deployed SHA target: `b14e1b038f838ffbcafd02ee1935291b213d6948`. Reference fap-web SHA: `b14e1b038f838ffbcafd02ee1935291b213d6948`.

## 4. Public Page Inventory
Scanned runtime rows: 160. Public indexable 200 rows observed: 73. Locale counts: `{'zh': 116, 'en': 40, 'unknown': 4}`. Page family counts: `{'home': 3, 'test_detail': 16, 'topic': 8, 'article': 26, 'career_guide': 22, 'career_job': 45, 'career_recommendation': 2, 'personality': 2, 'sitemap/llms': 4, 'content/help/policy': 26, 'unknown': 2, 'test_hub': 4}`. Sitemap URL count from fetched artifact: 261.

## 5. Content Asset Parity
Content pages: EN-PARITY-03 records 5 deferred EN import candidates and missing EN foundational pages `['brand', 'careers', 'charter', 'foundation', 'policies']`. Articles: EN-PARITY-04 has 10 target counterparts ready for review and 6 deferred English counterparts. Career guides: EN-PARITY-05 repo baseline has 36 guide codes ready for controlled review, but runtime exposure remains incomplete.

## 6. Result / Report Asset Parity
RESULT-EN-PARITY gates are active and fail-closed policies are recorded. RIASEC still has 14 deferred deep assets; MBTI records 8 missing English backend asset keys; Big Five V2 has 7 unreviewed draft EN asset groups.

## 7. Career / Recommendation Boundary
Runtime claim scan found 0 forbidden claim hits. Career wording must remain bounded to interest/workstyle signals and decision support. However sitemap exposes career job detail URLs without runtime 200 authority; sampled career job URLs returned 404 in about 15 seconds.

## 8. Media / OG / Alt Parity
EN-PARITY-06 confirms article alt metadata exists for current baselines, but shared cover visual/OCR review is still pending. Career guide OG image authority is incomplete: `72` missing career guide social image entries are recorded.

## 9. Canonical / Hreflang / Robots
Core `/`, `/en`, `/zh`, EN/ZH MBTI, EN/ZH RIASEC, and EN/ZH MBTI topic pages returned 200 with index/follow robots and expected canonical policy. No staging/www contamination was observed in core rows. Broken sitemap URLs are the main canonical/discoverability risk.

## 10. Sitemap / llms / GEO / JSON-LD / FAQ
P0: sitemap contains 18 hard-404 content/help/policy paths and 87 career job detail paths. `/en/support` and `/zh/support` appear in llms surfaces while returning 404. Core pages emit expected JSON-LD families: WebPage/Organization/ItemList for home and WebPage/BreadcrumbList/SoftwareApplication/FAQPage for test pages.

## 11. Visual / Responsive Findings
Core Playwright H1 check mode: `playwright-headless-core-h1-overflow-partial`. H1 issue count: `0`. Horizontal scroll count: `0`. RIASEC browser navigation timed out under the reduced 5s window, while curl returned 200 in ~15s; this is recorded as a visual/performance evidence gap.

## 12. Internal Link Readiness
Readiness is partial. MBTI topic/test hubs are live, but sitemap currently exposes invalid career and content edges. Do not add or expand internal links until P0 URL-surface cleanup is complete.

## 13. Claim Boundary Findings
No forbidden runtime claim hits were found in the bounded text scan. Continue enforcing no precise career recommendation, no hiring fit, no career success/salary prediction, and no diagnosis/treatment/cure language.

## 14. Staging / Baidu Sidecar
Staging containment remains active in the observed rows: noindex/nofollow/noarchive, robots containment, and sitemap/llms 410. Baidu stale staging result remains sidecar only; no external search API or removal/submission action was performed.

## 15. Priority Backlog
P0 findings: 2. P1 findings: 7. P2 findings: 3. Highest priority is sitemap/llms exposure cleanup for 404 content/help/policy and career job detail URLs.

## 16. Recommended Next Tasks
1. `GLOBAL-EN-ZH-PARITY-P0-FIX-TRAIN-01`: clean sitemap/llms exposure for 404 content/help/policy and career job detail URLs.
2. `GLOBAL-EN-ZH-PARITY-P1-CONTENT-ASSET-BATCH-01`: controlled human-reviewed content/article/career guide batches.
3. `GLOBAL-EN-ZH-PARITY-P1-RESULT-ASSET-BATCH-01`: reviewed RESULT English asset batches.
4. `GLOBAL-EN-ZH-PARITY-VISUAL-LONGTAIL-01`: long-tail visual/performance pass after P0 URL cleanup.

## 17. Validation
Validation commands are defined in the PR/task contract and will be run before commit/PR: focused PHP test, route list, Pint, composer validate/audit, JSON/YAML parses, diff checks, and fap-web reference status.

## 18. PR / Merge Result
Pending at report generation. This report is intended to be committed on branch `codex/global-en-zh-parity-scan-00` in fap-api only.

## 19. Sidecar Issues
- Broader Playwright representative visual pass timed out twice; core H1 checks completed and long-tail visual coverage is deferred.
- `llms-full.txt` large transfer showed partial-transfer behavior in curl during one observation, while runtime/post-deploy checks saw 200; recheck after P0 cleanup.
- Baidu stale staging result remains search-engine-side only.

## 20. What Was Not Done
No implementation fix, CMS mutation, Search Channel action, URL submission, external search API call, deploy, production migration, raw log read, production user data access, fap-web commit, or content generation was performed.

## 21. Final Decision
`global_en_zh_parity_scan_completed_ready_for_p0_fix_train`

## 22. Next Task
`GLOBAL-EN-ZH-PARITY-P0-FIX-TRAIN-01`
