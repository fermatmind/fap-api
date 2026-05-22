# MBTI Entity Map and URL Truth Review

Task: SEO-GROWTH-MBTI-01

Type: docs/generated/test only.

This contract defines the MBTI entity map and URL Truth review package for the growth loop. It does not write URL Truth, run collectors in write mode, read production DBs, mutate Search Channel Queue, change fap-web, or use frontend fallback/static sitemap/static llms/crawler logs/search responses/Digital PR mentions/local copies as authority.

## URL Truth Candidate Matrix

| Family | URL/path | page_entity_type | source_authority | Status |
| --- | --- | --- | --- | --- |
| MBTI test EN | `/en/tests/mbti-personality-test-16-personality-types` | `test_detail` | `scale_catalog` or `backend_public_surface` | candidate requiring backend-authoritative dry-run confirmation |
| MBTI test ZH | `/zh/tests/mbti-personality-test-16-personality-types` | `test_detail` | `scale_catalog` or `backend_public_surface` | candidate requiring backend-authoritative dry-run confirmation |
| MBTI research EN | `/en/research/mbti-personality-types-salary-turnover-report` | `research_report` | `backend_cms` / `research_reports` | candidate requiring claim-safe verification |
| MBTI research ZH | `/zh/research/mbti-personality-types-salary-turnover-report` | `research_report` | `backend_cms` / `research_reports` | candidate requiring claim-safe verification |
| MBTI topic EN/ZH | `/en/topics/mbti`, `/zh/topics/mbti` | `topic` | backend CMS topic authority | deferred until backend CMS/topic authority explicit |
| MBTI personality pages | `/en/personality/{type}`, `/zh/personality/{type}` | `personality` | backend personality/CMS authority | deferred until backend personality/CMS authority explicit |
| MBTI articles | backend CMS article URLs | `article` | backend CMS article rows | deferred until backend CMS article rows verified |
| Private flows | take/result/report/paywall/order/PDF/history | private/noindex | backend product truth only | excluded from URL Truth and Search Channel |

## Entity Families

Public/planning entities:

- `mbti_test`.
- `mbti_topic`.
- `mbti_research_salary_turnover`.
- `mbti_type_intj`.
- `mbti_type_infp`.
- `mbti_type_entj`.
- `mbti_type_{lowercase_type_code}`.

Private/funnel entities:

- `mbti_result_private`.
- `mbti_paid_report_private`.

## Entity Key Rules

- `entity_key` must be independent from URL slug.
- `translation_group_uuid` is preferred when available.
- `translation_group_id` is transitional only.
- slug/title similarity is migration-helper only, not authority.
- frontend fallback cannot create entity keys.
- crawler logs, static sitemap, static llms, search responses, local copies, and Digital PR mentions cannot create entity keys or URL Truth.

## Source Authority Rules

Allowed candidate source authorities:

- `scale_catalog`.
- `backend_public_surface`.
- `backend_cms`.
- `research_reports`.
- backend CMS topic/personality/article authority once explicit.

Forbidden source authorities:

- frontend fallback.
- static sitemap fallback.
- static llms fallback.
- crawler log source.
- search engine response.
- Digital PR mention.
- local copy.
- Node2 / business DB / Tencent RDS.

## Private and Noindex Boundary

The take, result, report, paywall, order, PDF, and history flows are private/noindex. They are product/funnel truth surfaces only and must remain excluded from public URL Truth and Search Channel planning.

## Next Task

After this PR merges, continue with `SEO-GROWTH-MBTI-02｜Content and Internal Link Wave 1 Dry-run Plan`.
