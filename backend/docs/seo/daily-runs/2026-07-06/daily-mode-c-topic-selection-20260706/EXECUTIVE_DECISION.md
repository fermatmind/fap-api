# DAILY-MODE-C-TOPIC-SELECTION-20260706

Date: 2026-07-06
Repo: fap-api
Scope: generated-only / read-only daily Mode C topic selection
Runtime impact: none
CMS impact: none
Search submission impact: none
Deploy impact: none

## Decision

Decision output: `go_for_mode_c_package_prompt_gaokao_major_adjustment_unacceptable_major_checklist`

The recommended Mode C topic is:

`gaokao-major-adjustment-unacceptable-major-checklist`

Recommended public article angle:

`被调剂到不想去的专业怎么办：用兴趣、课程和转专业条件做一次冷静复盘`

This topic should be selected before adjacent RIASEC topics because it has:

- high immediate user urgency;
- clean route back to the RIASEC test owner without promising admission, transfer, salary, or career outcomes;
- a natural checklist format for GEO/AEO answer surfaces;
- a clear claim-safe boundary: RIASEC is an exploration signal, not an admission or employment predictor;
- a strong fit with the existing Chinese RIASEC / gaokao / major cluster plan.

## Operation Type

Recommended operation type: `new_article_package_prompt_only`

This PR does not create the article body, CMS package, CMS draft, publication, schema, sitemap, `llms`, Search Channel, or search-provider submission. The selected topic should move next into a generated-only distribution asset package, then a separate authorized content-package lane if the operator approves.

## Primary Owner And Support Routes

Money owner:

- `/zh/tests/holland-career-interest-test-riasec`

Support routes already identified by the read-only train:

- `/zh/articles/gaokao-major-adjustment-unacceptable-major-checklist`
- `/zh/articles/riasec-holland-career-interest-test-explained`
- `/zh/articles/gaokao-score-major-shortlist-riasec-checklist`
- `/zh/articles/hot-major-fit-riasec-course-career-checklist`

Private result, report, attempt, lookup, order, account, payment, and tokenized URLs remain excluded from the plan.

## Held Lanes

Held until separate exact authorization:

- CMS draft/import/write/publish;
- runtime page changes;
- public frontend changes;
- title/meta/H1 mutation on existing pages;
- schema, hreflang, canonical, noindex, sitemap, `llms`, or `llms-full`;
- GSC Request Indexing, IndexNow, Baidu, 360, Sogou, Shenma, or Search Channel writes;
- staging or production deploy verification.

## Next Train Item

Proceed to:

`DAILY-DISTRIBUTION-ASSET-PACKAGE-20260706`

Reason: this PR selects one topic only. The next PR may generate non-published distribution assets for the selected topic, still under generated-only scope.

## Deferred Items

This PR intentionally does not:

- write or rewrite article body content;
- create CMS import packages;
- publish or unpublish anything;
- make public URL, sitemap, `llms`, schema, canonical, noindex, Search, or deploy changes;
- use GSC/GA as purchase truth;
- treat missing analytics exports as zero.
