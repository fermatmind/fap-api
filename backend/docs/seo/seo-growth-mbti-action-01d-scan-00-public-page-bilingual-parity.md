# SEO-GROWTH-MBTI-ACTION-01D-SCAN-00 Report

## 1. Executive Summary

Final decision: `mbti_01d_scan_completed_ready_for_p0_visual_parity_fix`.

This read-only scan found P0 issues on production public MBTI surfaces: the EN homepage H1 has confirmed desktop/laptop overflow, the ZH MBTI test H1 has confirmed desktop/laptop overflow, both EN/ZH Research core URLs return 404 in manual public curl checks, and core homepage/research hreflang reciprocity is incomplete. The scan did not mutate CMS, Search Channel, URL Truth, fap-web, production env, DNS, deploy state, or runtime code.

Counts: scanned URLs `35`, public indexable observations `27`, private/noindex observations `0`, P0 `10`, P1 `42`, P2 `8`.

## 2. Scan Method

- fap-api was the report/aggregator repository.
- fap-web was inspected reference-only; no fap-web files were modified or staged.
- Production runtime was observed with bounded public HTTP fetches and Playwright headless Chromium DOM checks.
- Backend URL Truth was observed through the safe local dry-run collector metadata: `php artisan seo-intel:collect --collector=url_truth_inventory --dry-run --json`.
- Sitemap, `llms.txt`, and `llms-full.txt` were used as observation surfaces only, not authority.
- Claim markers were classified with context; explicit non-diagnostic disclaimers were not treated as unsafe claims.

## 3. URL Inventory Scope

Inventory sources:

- Production sitemap: status `200`, URL count `114`.
- Production llms.txt: status `0`, URL count `0`.
- Production llms-full.txt: status `0`, URL count `0`.
- Backend URL Truth dry-run: status `success`, items seen `7`.
- fap-web route family references: `12`.

Runtime URL cap used in the successful scan: 35 URLs. This is below the allowed maximum and still includes all requested MBTI core URLs.

## 4. Page Family Coverage

```json
{
  "home": 3,
  "test_detail": 6,
  "research_report": 2,
  "topic": 4,
  "help/content/policy": 8,
  "career_guide": 2,
  "unknown": 4,
  "career_recommendation": 2,
  "personality": 2,
  "test_hub": 2
}
```

## 5. MBTI Core Page Findings

- https://fermatmind.com/: status=200, canonical=https://fermatmind.com, h1=看清自己，走好每一步, hreflang=3, json_ld=WebPage,ItemList,Organization.
- https://fermatmind.com/en: status=200, canonical=https://fermatmind.com/en, h1=Understand yourself before the next step., hreflang=3, json_ld=WebPage,ItemList,Organization.
- https://fermatmind.com/zh: status=200, canonical=https://fermatmind.com, h1=看清自己，走好每一步, hreflang=3, json_ld=WebPage,ItemList,Organization.
- https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types: status=200, canonical=https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types, h1=MBTI Personality Test 【16 Personality Types】, hreflang=3, json_ld=WebPage,BreadcrumbList,SoftwareApplication,FAQPage.
- https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types: status=200, canonical=https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types, h1=MBTI 性格测试【16型人格】, hreflang=3, json_ld=WebPage,BreadcrumbList,SoftwareApplication,FAQPage.
- https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report: status=404, canonical=None, h1=None, hreflang=0, json_ld=.
- https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report: status=404, canonical=None, h1=None, hreflang=0, json_ld=.
- https://fermatmind.com/en/topics/mbti: status=200, canonical=https://fermatmind.com/en/topics/mbti, h1=MBTI Topic Cluster, hreflang=3, json_ld=CollectionPage,WebPage,BreadcrumbList.
- https://fermatmind.com/zh/topics/mbti: status=200, canonical=https://fermatmind.com/zh/topics/mbti, h1=MBTI 主题内容聚合, hreflang=3, json_ld=CollectionPage,WebPage,BreadcrumbList.

Manual public curl checks confirmed both Research apex URLs return 404:

- `https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report` -> 404, title `Page Not Found | FermatMind`.
- `https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report` -> 404, title `Page Not Found | FermatMind`.

## 6. Bilingual Pair Findings

Core pair gaps:

- core bilingual pair or reciprocal hreflang gap — https://fermatmind.com/en -> https://fermatmind.com/; pair_exists=True, points_to_pair=False, reciprocal=True.
- core bilingual pair or reciprocal hreflang gap — https://fermatmind.com/zh -> https://fermatmind.com/en; pair_exists=True, points_to_pair=True, reciprocal=False.
- core bilingual pair or reciprocal hreflang gap — https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report -> https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report; pair_exists=True, points_to_pair=False, reciprocal=False.
- core bilingual pair or reciprocal hreflang gap — https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report -> https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report; pair_exists=True, points_to_pair=False, reciprocal=False.

Homepage note: `/zh` renders the ZH homepage but canonicalizes to `/`; EN `/en` did not point to the canonical root ZH pair in the captured hreflang set, while `/zh` did point to `/en`. This creates a reciprocal/canonical ambiguity for the root ZH homepage cluster.

## 7. Canonical / Hreflang / Robots Findings

- P0: broken core public URL — https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report status=404 canonical=
- P0: broken core public URL — https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report status=404 canonical=

No staging or `www` canonical contamination was found in the successful public runtime sample. Private/noindex flows were excluded from the public inventory and no private indexable leak was observed in the bounded sample.

## 8. Visual / Responsive Findings

Visual scan mode: `playwright_headless_chromium`.

P0 visual findings:

- severe H1 visual truncation/overflow risk — https://fermatmind.com/en (desktop); H1 `Understand yourself before the next step.`, scrollWidth=1719, clientWidth=960, whiteSpace=nowrap.
- severe H1 visual truncation/overflow risk — https://fermatmind.com/en (laptop); H1 `Understand yourself before the next step.`, scrollWidth=1719, clientWidth=960, whiteSpace=nowrap.
- severe H1 visual truncation/overflow risk — https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types (desktop); H1 `MBTI 性格测试【16型人格】`, scrollWidth=475, clientWidth=450, whiteSpace=nowrap.
- severe H1 visual truncation/overflow risk — https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types (laptop); H1 `MBTI 性格测试【16型人格】`, scrollWidth=475, clientWidth=450, whiteSpace=nowrap.

Representative P1 visual findings:

- https://fermatmind.com/ (desktop): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=18.
- https://fermatmind.com/ (laptop): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=18.
- https://fermatmind.com/ (mobile): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=19.
- https://fermatmind.com/en (desktop): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=16.
- https://fermatmind.com/en (laptop): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=16.
- https://fermatmind.com/en (mobile): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=16.
- https://fermatmind.com/zh (desktop): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=18.
- https://fermatmind.com/zh (laptop): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=18.
- https://fermatmind.com/zh (mobile): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=19.
- https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types (desktop): horizontal=None, nav=None, footer=None, cta_count=0, heading_count=0.
- https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types (laptop): horizontal=None, nav=None, footer=None, cta_count=0, heading_count=0.
- https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types (mobile): horizontal=None, nav=None, footer=None, cta_count=0, heading_count=0.
- https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types (desktop): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=4.
- https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types (laptop): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=4.
- https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types (mobile): horizontal=False, nav=False, footer=False, cta_count=0, heading_count=4.
- https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report (desktop): horizontal=None, nav=None, footer=None, cta_count=0, heading_count=0.
- https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report (laptop): horizontal=None, nav=None, footer=None, cta_count=0, heading_count=0.
- https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report (mobile): horizontal=None, nav=None, footer=None, cta_count=0, heading_count=0.
- https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report (desktop): horizontal=None, nav=None, footer=None, cta_count=0, heading_count=0.
- https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report (laptop): horizontal=None, nav=None, footer=None, cta_count=0, heading_count=0.

The user-observed EN homepage truncation is confirmed by DOM metrics: H1 text `Understand yourself before the next step.` has `scrollWidth=1719` and `clientWidth=960` with `whiteSpace=nowrap` on desktop and laptop.

## 9. Internal Link Readiness

- test -> topic: runtime_observed_only (5 examples captured).
- test -> research: missing (0 examples captured).
- test -> article: runtime_observed_only (5 examples captured).
- topic -> test: runtime_observed_only (5 examples captured).
- topic -> research: missing (0 examples captured).
- topic -> article: runtime_observed_only (5 examples captured).
- research -> test: missing (0 examples captured).
- research -> topic: missing (0 examples captured).
- article -> test: missing (0 examples captured).
- article -> topic: missing (0 examples captured).
- article -> research: missing (0 examples captured).
- personality -> test: runtime_observed_only (5 examples captured).
- personality -> topic: missing (0 examples captured).
- personality -> article: runtime_observed_only (4 examples captured).

The captured runtime links show test/topic/article and personality/test/article edges, but research edges are missing because the Research core pages return 404 and no valid research runtime link targets were observed in the bounded sample.

## 10. Claim Boundary Findings

- Observation: https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types markers=诊断; context=allowed_non_diagnostic_disclaimer; snippet=这是诊断吗？不是。本测评用于教育性自我理解，不替代专业建议。
- P1: https://fermatmind.com/zh/method-boundaries markers=诊断,治疗; context=page_404_observation_not_indexable_copy; snippet=runtime returned 404 during manual verification
- Observation: https://fermatmind.com/en/tests/category/career markers=treatment; context=allowed_non_diagnostic_disclaimer; snippet=not formal diagnosis, treatment, or legal guidance
- Observation: https://fermatmind.com/en/tests/category/personality markers=treatment; context=allowed_non_diagnostic_disclaimer; snippet=not formal diagnosis, treatment, or legal guidance
- Observation: https://fermatmind.com/zh/tests/category/career markers=诊断; context=allowed_non_diagnostic_disclaimer; snippet=不替代医疗、法律或诊断意见；不能替代专业诊疗、法律意见或高风险决策中的正式评估。
- Observation: https://fermatmind.com/zh/tests/category/personality markers=诊断; context=allowed_non_diagnostic_disclaimer; snippet=不替代医疗、法律或诊断意见；不能替代专业诊疗、法律意见或高风险决策中的正式评估。

No unsafe overclaim was confirmed. The raw markers found in the sample were in explicit non-diagnostic disclaimer contexts such as `不是。本测评用于教育性自我理解，不替代专业建议` and `not formal diagnosis, treatment, or legal guidance`.

## 11. GEO / FAQ / JSON-LD Findings

- P1: FAQPage JSON-LD not clearly backed by visible FAQ sample — https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types
- P1: research page lacks Article/Dataset/WebPage JSON-LD type in parsed scripts — https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report
- P1: research page lacks Article/Dataset/WebPage JSON-LD type in parsed scripts — https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report

Core MBTI test pages expose `WebPage`, `BreadcrumbList`, `SoftwareApplication`, and `FAQPage`. Topic pages expose `CollectionPage`, `WebPage`, and `BreadcrumbList`. Research pages could not be validated for schema because both requested Research URLs return 404.

## 12. Priority Backlog

P0 backlog:

- broken public core URL returns 404 — https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report; status=404; evidence=manual curl -L public runtime check.
- broken public core URL returns 404 — https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report; status=404; evidence=manual curl -L public runtime check.
- severe H1 visual truncation/overflow risk — https://fermatmind.com/en (desktop); H1 `Understand yourself before the next step.`, scrollWidth=1719, clientWidth=960, whiteSpace=nowrap.
- severe H1 visual truncation/overflow risk — https://fermatmind.com/en (laptop); H1 `Understand yourself before the next step.`, scrollWidth=1719, clientWidth=960, whiteSpace=nowrap.
- severe H1 visual truncation/overflow risk — https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types (desktop); H1 `MBTI 性格测试【16型人格】`, scrollWidth=475, clientWidth=450, whiteSpace=nowrap.
- severe H1 visual truncation/overflow risk — https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types (laptop); H1 `MBTI 性格测试【16型人格】`, scrollWidth=475, clientWidth=450, whiteSpace=nowrap.
- core bilingual pair or reciprocal hreflang gap — https://fermatmind.com/en -> https://fermatmind.com/; pair_exists=True, points_to_pair=False, reciprocal=True.
- core bilingual pair or reciprocal hreflang gap — https://fermatmind.com/zh -> https://fermatmind.com/en; pair_exists=True, points_to_pair=True, reciprocal=False.
- core bilingual pair or reciprocal hreflang gap — https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report -> https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report; pair_exists=True, points_to_pair=False, reciprocal=False.
- core bilingual pair or reciprocal hreflang gap — https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report -> https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report; pair_exists=True, points_to_pair=False, reciprocal=False.

P1 backlog, first 20:

- visual/responsive overflow risk — https://fermatmind.com/ (desktop).
- visual/responsive overflow risk — https://fermatmind.com/ (laptop).
- visual/responsive overflow risk — https://fermatmind.com/ (mobile).
- visual/responsive overflow risk — https://fermatmind.com/en (mobile).
- visual/responsive overflow risk — https://fermatmind.com/zh (desktop).
- visual/responsive overflow risk — https://fermatmind.com/zh (laptop).
- visual/responsive overflow risk — https://fermatmind.com/zh (mobile).
- visual/responsive overflow risk — https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types (desktop).
- visual/responsive overflow risk — https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types (laptop).
- visual/responsive overflow risk — https://fermatmind.com/en/tests/mbti-personality-test-16-personality-types (mobile).
- visual/responsive overflow risk — https://fermatmind.com/zh/tests/mbti-personality-test-16-personality-types (mobile).
- visual/responsive overflow risk — https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report (desktop).
- visual/responsive overflow risk — https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report (laptop).
- visual/responsive overflow risk — https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report (mobile).
- visual/responsive overflow risk — https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report (desktop).
- visual/responsive overflow risk — https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report (laptop).
- visual/responsive overflow risk — https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report (mobile).
- visual/responsive overflow risk — https://fermatmind.com/en/topics/mbti (desktop).
- visual/responsive overflow risk — https://fermatmind.com/en/topics/mbti (laptop).
- visual/responsive overflow risk — https://fermatmind.com/en/topics/mbti (mobile).

P2 backlog:

- non-core help/content/policy parity observed only; detailed copy parity deferred — https://fermatmind.com/en/about
- non-core help/content/policy parity observed only; detailed copy parity deferred — https://fermatmind.com/zh/about
- non-core help/content/policy parity observed only; detailed copy parity deferred — https://fermatmind.com/zh/careers
- non-core help/content/policy parity observed only; detailed copy parity deferred — https://fermatmind.com/en/help/about
- non-core help/content/policy parity observed only; detailed copy parity deferred — https://fermatmind.com/en/help/for-business-and-research
- non-core help/content/policy parity observed only; detailed copy parity deferred — https://fermatmind.com/zh/help/for-business-and-research
- non-core help/content/policy parity observed only; detailed copy parity deferred — https://fermatmind.com/en/method-boundaries
- non-core help/content/policy parity observed only; detailed copy parity deferred — https://fermatmind.com/zh/method-boundaries

## 13. Recommended Next Tasks

- SEO-GROWTH-MBTI-ACTION-01D-P0-VISUAL-PARITY-FIX-01: fap-web visual parity fix only (if homepage/test H1 or CTA overflow is confirmed)
- SEO-GROWTH-MBTI-ACTION-01D-P0-HREFLANG-CANONICAL-FIX-01: fap-web metadata/hreflang only (if core reciprocal hreflang/canonical gaps are accepted as P0)
- SEO-GROWTH-MBTI-ACTION-01D-P1-INTERNAL-LINK-WAVE-01: backend/CMS-authoritative internal link wave; no frontend fallback authority (after P0 visual/canonical issues are closed)

Expected next task: `SEO-GROWTH-MBTI-ACTION-01D-P0-VISUAL-PARITY-FIX-01`.

## 14. Validation

Required validation commands for this PR:

```bash
cd /Users/rainie/Desktop/GitHub/fap-api/backend
php artisan test --filter=SeoIntelGrowthMbtiAction01dScan00PublicPageBilingualParity --no-ansi
php artisan route:list --no-ansi
vendor/bin/pint --test

cd /Users/rainie/Desktop/GitHub/fap-api
python3 -m json.tool backend/docs/seo/generated/seo-growth-mbti-action-01d-scan-00-public-page-bilingual-parity.v1.json >/dev/null
python3 -m json.tool docs/codex/pr-train-state.json >/dev/null
python3 - <<'PY'
import yaml, pathlib
yaml.safe_load(pathlib.Path('docs/codex/pr-train.yaml').read_text())
PY
git diff --check
git diff --cached --check
```

Reference validation requested for fap-web is listed as a PR validation target. At scan time, fap-web was on `codex/search-channel-live-zh-mbti-01a-indexnow-keylocation-fix` with untracked `.playwright-mcp/`; this repo is reference-only and was not modified.

## 15. PR / Merge Result

Pending. This file is the report artifact for branch `codex/seo-growth-mbti-action-01d-scan-00`.

## 16. Sidecar Issues

- `api.fermatmind.com/api/v0.5/seo/sitemap-source` public curl from this local environment failed with `LibreSSL SSL_connect: SSL_ERROR_SYSCALL`; backend URL Truth was therefore represented by local safe dry-run metadata plus production public runtime observations.
- fap-web reference worktree was not on main and had untracked `.playwright-mcp/`; no fap-web changes were staged or committed.
- Production DB, production env/secrets, Redis, S3, CDN, Search Console, Baidu, IndexNow, Bing, 360, Sogou, Shenma, and raw Nginx logs were not inspected by design.

## 17. What Was Not Done

- No implementation.
- No CMS mutation.
- No Search Channel enqueue or live submission.
- No URL Truth mutation.
- No internal link creation.
- No article publish or content rewrite.
- No deploy, SSH, DNS/nginx/env edits, production migration, or production secret reads.
- No fap-web code changes.
- No committed screenshots.

## 18. Final Decision

`mbti_01d_scan_completed_ready_for_p0_visual_parity_fix`

## 19. Next Task

`SEO-GROWTH-MBTI-ACTION-01D-P0-VISUAL-PARITY-FIX-01`
