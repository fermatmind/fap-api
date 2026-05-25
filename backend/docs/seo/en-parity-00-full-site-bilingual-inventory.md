# EN-PARITY-00 Full-Site Bilingual Inventory

## Executive Summary

Final decision: `blocked_english_parity_p0_gaps_found`.

This is a read-only full-site EN/ZH bilingual parity inventory for `https://fermatmind.com`. It lands the temporary scan artifact from `/tmp/en-parity-00-scan.json` into fap-api as the baseline for the EN-PARITY repair train. It does not implement fixes and does not mutate CMS, Search Channel, production runtime, production migration state, fap-web, sitemap, llms, media assets, or URL submission systems.

Key counts:

- URL discovered: `180`
- URL scanned: `180`
- EN URLs: `73`
- ZH URLs: `106`
- Unknown URLs: `1`
- Hard 404: `2`
- Soft 404: `7`
- Article EN/ZH: `9` / `19`
- Missing EN article counterparts: `10`
- Career guide detail EN/ZH: `0` / `20`
- Missing EN career guide counterparts: `20`
- P0/P1/P2: `45` / `12` / `3`

## Scope And Method

Inputs:

- Production runtime: `https://fermatmind.com`
- fap-api repository: `/Users/rainie/Desktop/GitHub/fap-api`
- fap-web repository: `/Users/rainie/Desktop/GitHub/fap-web` as reference-only
- Chrome / Computer Use observation for `fermatmind.com/en`
- Production `sitemap.xml`, `llms.txt`, and `llms-full.txt` as observation surfaces only
- Local backend URL Truth dry-run metadata where available

Authority rule: backend/CMS URL Truth remains authority. Sitemap, llms, frontend fallback, and runtime HTML are observation surfaces, not authority.

## Page Family Coverage

```json
{
  "home": 3,
  "help_content_policy": 25,
  "article_index": 2,
  "article": 28,
  "career_landing": 3,
  "career_guide_index": 2,
  "career_recommendation": 2,
  "test_hub": 4,
  "personality_index": 2,
  "personality": 64,
  "test_detail": 12,
  "test_category": 4,
  "topic_index": 2,
  "topic": 6,
  "unknown": 1,
  "career_guide": 20
}
```

## Hard 404 Findings

- `https://fermatmind.com/en/about`
- `https://fermatmind.com/en/support`

## Soft 404 Findings

Soft 404 means HTTP 200 with `Page Not Found | FermatMind` metadata and missing/incomplete canonical evidence.

- `https://fermatmind.com/en/help/about`
- `https://fermatmind.com/en/help/for-business-and-research`
- `https://fermatmind.com/en/method-boundaries`
- `https://fermatmind.com/zh/about`
- `https://fermatmind.com/zh/careers`
- `https://fermatmind.com/zh/help/contact`
- `https://fermatmind.com/zh/support`

## Article Counterpart Gap

Runtime discovery found article pages EN/ZH: `9` / `19`. The following ZH article URLs do not have discovered EN counterparts:

- `https://fermatmind.com/zh/articles/big-five-growth-guide`
- `https://fermatmind.com/zh/articles/big-five-narrative-portrait`
- `https://fermatmind.com/zh/articles/big-five-tool-guide`
- `https://fermatmind.com/zh/articles/eq-test-tool-guide`
- `https://fermatmind.com/zh/articles/iq-test-growth-guide`
- `https://fermatmind.com/zh/articles/iq-test-narrative-portrait`
- `https://fermatmind.com/zh/articles/iq-test-tool-guide`
- `https://fermatmind.com/zh/articles/mbti-basics`
- `https://fermatmind.com/zh/articles/mbti-growth-guide`
- `https://fermatmind.com/zh/articles/mbti-narrative-portrait`

## Career Guide Counterpart Gap

Runtime discovery found career guide detail pages EN/ZH: `0` / `20`. The following ZH career guide URLs do not have discovered EN counterparts:

- `https://fermatmind.com/zh/career/guides/annual-career-review-system`
- `https://fermatmind.com/zh/career/guides/big5-for-career-decisions`
- `https://fermatmind.com/zh/career/guides/build-five-year-career-roadmap`
- `https://fermatmind.com/zh/career/guides/build-portfolio-for-career-switch`
- `https://fermatmind.com/zh/career/guides/career-growth-with-manager`
- `https://fermatmind.com/zh/career/guides/career-risk-management`
- `https://fermatmind.com/zh/career/guides/career-transition-playbook`
- `https://fermatmind.com/zh/career/guides/cross-industry-move-strategy`
- `https://fermatmind.com/zh/career/guides/first-90-days-in-new-role`
- `https://fermatmind.com/zh/career/guides/from-mbti-to-job-fit`
- `https://fermatmind.com/zh/career/guides/how-to-choose-college-major`
- `https://fermatmind.com/zh/career/guides/how-to-find-right-career-direction`
- `https://fermatmind.com/zh/career/guides/improve-workplace-competitiveness`
- `https://fermatmind.com/zh/career/guides/interview-strategy-by-role`
- `https://fermatmind.com/zh/career/guides/iq-eq-balance-at-work`
- `https://fermatmind.com/zh/career/guides/leader-track-vs-expert-track`
- `https://fermatmind.com/zh/career/guides/networking-that-actually-works`
- `https://fermatmind.com/zh/career/guides/personal-brand-for-professionals`
- `https://fermatmind.com/zh/career/guides/prevent-burnout-while-growing`
- `https://fermatmind.com/zh/career/guides/salary-negotiation-framework`

## MBTI Research Carryover

Prior merged scan PR #1661 recorded both MBTI research apex URLs returning 404. EN-PARITY-00 carries them as P0 backlog evidence even though they were not present in this 180-URL sitemap/llms discovery set:

- `https://fermatmind.com/en/research/mbti-personality-types-salary-turnover-report`
- `https://fermatmind.com/zh/research/mbti-personality-types-salary-turnover-report`

## Authority Evidence Gaps

- `chrome_batch_visual_gap`: Chrome batch visual audit not completed. Evidence: Chrome/Computer Use confirmed fermatmind.com/en structure, but Chrome-backed batch screenshot automation was unavailable/occupied; visual parity remains a follow-up EN-PARITY-08 task.
- `backend_url_truth_coverage_gap`: Backend URL Truth dry-run coverage is smaller than runtime inventory. Evidence: url_truth_inventory dry-run succeeded with items_seen=7 while public runtime discovery found 180 URLs.
- `internal_link_graph_local_mysql_gap`: Internal-link graph dry-run blocked by local MySQL credentials. Evidence: seo-intel:internal-link-graph --dry-run --no-write --json failed locally with SQLSTATE[HY000] [1045] Access denied for root@localhost.

## Claim Boundary Notes

The scan produced three P2 claim-boundary observations. Two English `cure` hits are substring false positives from `secure`; the Chinese `职业成功率` hit appears in a negated boundary statement explaining that the Holland test cannot predict career success rate. No CMS or copy mutation is performed in this PR.

## Recommended PR Train

1. `EN-PARITY-01 URL Truth / hard 404 / soft 404 / canonical baseline`
2. `EN-PARITY-02 Translation group schema/read model`
3. `EN-PARITY-03 Content pages EN parity`
4. `EN-PARITY-04 Articles EN counterpart batch`
5. `EN-PARITY-05 Career guides EN counterpart batch`
6. `EN-PARITY-06 Media parity`
7. `EN-PARITY-07 Sitemap / llms / JSON-LD / FAQ grounding parity gate`
8. `EN-PARITY-08 Chrome / Playwright visual parity pass`

## Reproduction Notes

The source scan was generated by a temporary script under `/tmp` and materialized from `/tmp/en-parity-00-scan.json`. Temporary scripts and screenshots are intentionally not committed. To reproduce, rerun a bounded public runtime inventory against `https://fermatmind.com`, collect sitemap/llms URL observations, parse canonical/hreflang/title/H1/JSON-LD/images/links, and compare EN/ZH counterparts by normalized path and locale prefix.

## Non-Mutation Statement

- no CMS mutation
- no fap-web commit
- no deploy
- no production migration
- no URL submission
- no sitemap generator change
- no llms generator change
- no runtime implementation fix

## Next Task

`EN-PARITY-01 URL Truth / hard 404 / soft 404 / canonical baseline`
