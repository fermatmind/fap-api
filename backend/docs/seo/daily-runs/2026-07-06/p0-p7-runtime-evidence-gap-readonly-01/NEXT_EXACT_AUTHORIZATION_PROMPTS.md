# Next Exact Authorization Prompts

## Recommended Validation Prompt

```text
/goal SIX-TEST-HUB-12-ROUTE-RUNTIME-READBACK-01

Run a fap-api generated-only/read-only runtime readback for all 12 public test hub routes:
zh/en MBTI, Big Five, Enneagram, RIASEC, IQ, EQ.

For each route record HTTP, final URL, canonical, robots, title, meta description, H1, visible FAQ count, FAQPage JSON-LD count, CTA links, free/free-result language, visible claim boundary, private URL guard, sitemap presence, llms.txt presence, and llms-full presence.

Use a bounded and reliable llms-full method. Do not mutate CMS, runtime, API, Search Channel, sitemap, llms, schema, hreflang, fap-web, DB, or deploy.
```

## Recommended Data-Quality Prompt

```text
/goal GSC-SEO-INTEL-LIVE-READMODEL-QUALITY-PROOF-READONLY-01

Prove whether current seo_intel/GSC read-model rows are live, non-fixture, sanitized, fresh/finalized, and pass GscDataQualityGate.

Do not call live GSC, import data, expose raw URLs/queries, enqueue Search Channel, or write CMS unless separately authorized.
```

## Recommended Growth Prompt

```text
/goal RIASEC-GAOKAO-ADJUSTMENT-MODE-C-CONTENT-PACKAGE-01

Create a generated-only Mode C content-package prompt and QA checklist for `gaokao-major-adjustment-unacceptable-major-checklist`, using the existing topic-selection and distribution asset packages.

No CMS write, import, publish, search submission, sitemap/llms/schema/hreflang mutation, fap-web edit, runtime mutation, or deploy.
```

## Held Prompt Requiring Fresh Authorization

```text
/goal RIASEC-ZH-TEST-LANDING-FAQ-PARITY-READBACK-01

Run a read-only DOM/runtime FAQ parity readback for `/zh/tests/holland-career-interest-test-riasec`.
No FAQ/schema/runtime/CMS/fap-web/Search/deploy mutation.
```

This was explicitly excluded from the earlier train and therefore needs fresh authorization.
