# MBTI Baseline URL Truth and Telemetry Contract

Task: SEO-GROWTH-MBTI-00A

Type: docs/generated/test only.

This blocker-resolution contract resolves the `blocked_mbti_url_truth_unclear` scan outcome by defining the MBTI baseline asset inventory, URL Truth candidate matrix, entity map contract, telemetry contract, private/noindex boundary, Search Channel preconditions, and claim-gate preconditions required before the full MBTI growth train can start.

This PR does not write URL Truth, enqueue Search Channel, submit URLs, mutate CMS content, read production crawler logs, write to `seo_intel`, deploy, run migrations, enable schedulers, send Digital PR outreach, auto-fix claims, auto-create internal links, create pSEO, modify fap-web, or touch business DB / Tencent RDS / Node2 DB.

## Baseline Asset Inventory

The MBTI growth loop baseline covers these asset families:

- MBTI test page.
- MBTI research report.
- MBTI topic hub.
- MBTI personality type pages.
- MBTI articles.
- MBTI private flows: take, result, report, paywall, order, PDF, and history.
- MBTI telemetry surfaces.
- MBTI Search Channel candidates.
- MBTI claim-sensitive copy surfaces.

Frontend fallback, static sitemap, static llms, crawler logs, search engine responses, Digital PR mentions, and local copies are not authority.

## URL Truth Candidate Matrix

| Family | URL or path | Expected page_entity_type | Expected source authority | Status |
| --- | --- | --- | --- | --- |
| MBTI test page | `/en/tests/mbti-personality-test-16-personality-types` | `test_detail` | `scale_catalog` or `backend_public_surface` | candidate requiring backend-authoritative dry-run confirmation |
| MBTI test page | `/zh/tests/mbti-personality-test-16-personality-types` | `test_detail` | `scale_catalog` or `backend_public_surface` | candidate requiring backend-authoritative dry-run confirmation |
| MBTI research report | `/en/research/mbti-personality-types-salary-turnover-report` | `research_report` | `backend_cms` / `research_reports` | candidate requiring claim-safe verification |
| MBTI research report | `/zh/research/mbti-personality-types-salary-turnover-report` | `research_report` | `backend_cms` / `research_reports` | candidate requiring claim-safe verification |
| MBTI topic hub | `/en/topics/mbti` | `topic` | backend CMS topic authority | deferred until backend CMS/topic authority is explicit |
| MBTI topic hub | `/zh/topics/mbti` | `topic` | backend CMS topic authority | deferred until backend CMS/topic authority is explicit |
| MBTI personality type pages | `/en/personality/{type}` | `personality` | backend personality/CMS entity authority | deferred until backend personality/CMS entity authority is explicit |
| MBTI personality type pages | `/zh/personality/{type}` | `personality` | backend personality/CMS entity authority | deferred until backend personality/CMS entity authority is explicit |
| MBTI articles | backend CMS article URLs | `article` | backend CMS article rows | deferred until backend CMS article rows are verified |
| Private flows | take, result, report, paywall, order, PDF, history | private/noindex | backend product truth only | excluded from URL Truth and Search Channel |

## Entity Map Contract

Future MBTI entity keys:

- `mbti_test`.
- `mbti_topic`.
- `mbti_research_salary_turnover`.
- `mbti_result_private`.
- `mbti_paid_report_private`.
- `mbti_type_intj`.
- `mbti_type_infp`.
- `mbti_type_entj`.
- Additional MBTI type keys follow `mbti_type_{lowercase_type_code}`.

Entity rules:

- `entity_key` must be independent from URL slug.
- `translation_group_uuid` is preferred when available.
- `translation_group_id` is transitional only.
- slug/title similarity is a migration helper only, not authority.
- frontend fallback cannot create an entity key.
- private result and paid report entities are product/funnel entities only; they are not public URL Truth or Search Channel candidates.

## Telemetry Contract

Frontend observation events:

- `landing_view`.
- `test_cta_click`.
- `test_start_click`.
- `report_preview_view`.
- `unlock_click`.
- `checkout_button_click`.
- `email_form_view`.

Backend truth events:

- `attempt_created`.
- `attempt_submitted`.
- `result_generated`.
- `email_captured`.
- `order_created`.
- `payment_success`.
- `benefit_granted`.
- `report_access_granted`.
- `pdf_generated`.

Telemetry rules:

- frontend observation is not backend truth.
- backend payment, order, and report access are truth.
- bot and crawler traffic must be excluded from conversion funnel formulas.
- crawler traffic may only enter crawler aggregate observation.
- email must not enter public HTML, search, analytics payloads, URLs, or Digital PR artifacts.

## Private and Noindex Exclusion Boundary

The following MBTI paths are excluded from URL Truth, Search Channel, public growth URL lists, sitemap promotion, and Digital PR URL targets:

- take flow.
- result page.
- report page.
- paywall and checkout.
- order and payment recovery paths.
- report PDF.
- account/history pages.
- email capture or recovery paths.

These surfaces may be used only for backend truth, entitlement, payment, report access, and human-only funnel review.

## Claim Gate Boundary

MBTI claim lint is required before:

- Search Channel planning.
- Digital PR Wave 2.
- Content/Internal Link Wave 1.
- growth experiment review.

Forbidden claims:

- MBTI决定收入.
- MBTI预测离职.
- 薪资保证.
- 招聘适配.
- 职业成功预测.
- 精准职业推荐.
- 最适合职业.
- 诊断.
- 确诊.
- 治疗.
- 治愈.

Allowed bounded language:

- 模型化指数.
- 聚合层面.
- 方向性趋势.
- 非诊断.
- 结果仅供参考.
- 职业方向参考.
- 工作方式倾向.
- 探索建议.

Claim lint may flag or block copy. It must not auto-rewrite CMS content, Digital PR copy, Search Channel candidates, or internal links.

## Search Channel Preconditions

MBTI Search Channel planning may proceed only when every candidate satisfies:

- URL Truth verified.
- `source_authority` allowed.
- canonical, indexable, and public.
- claim-safe.
- not private.
- not draft.
- not noindex.
- dry-run before enqueue.
- human approval before live submit.
- no bulk submit.

Search response is observation only and cannot become URL Truth.

## Next Task

If this blocker-resolution PR completes and merges, the next task is:

`SEO-GROWTH-MBTI-PR-TRAIN-01`
