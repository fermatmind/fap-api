# Future Dry-Run Options

This diagnostic does not authorize writes. The options below are follow-up PR candidates only.

## Option 1: FAQ Parity Readback

PR id: `RIASEC-ZH-TEST-LANDING-FAQ-PARITY-READBACK-01`

Type: generated-only read-only evidence PR.

Purpose: verify whether rendered visible FAQ questions match FAQPage JSON-LD questions on the public page.

Likely output:

- rendered DOM FAQ extraction;
- JSON-LD FAQPage extraction;
- visible-vs-JSON-LD equality result;
- source-of-truth notes;
- exact repair prompt if mismatch is confirmed.

Forbidden:

- no schema mutation;
- no frontend repair;
- no FAQ copy write;
- no CMS write;
- no deploy.

Recommended priority: first.

## Option 2: FAQ/GEO Authority Dry-Run

PR id: `RIASEC-ZH-TEST-LANDING-FAQ-GEO-AUTHORITY-DRYRUN-01`

Type: generated-only backend authority planning PR.

Purpose: propose backend/CMS-authority answer-surface improvements for:

- "霍兰德职业兴趣测试免费吗";
- difference between `60题标准版` and `140题增强版`;
- RIASEC as exploration signal, not a precise career or major recommendation;
- course, job activity, and major validation framing without overclaiming.

Prerequisite: FAQ parity readback completed or explicitly held.

Forbidden:

- no final FAQ copy write;
- no title/meta write;
- no schema write;
- no CMS import/publish.

Recommended priority: second.

## Option 3: CTA/Internal Link Deep Readback

PR id: `RIASEC-ZH-TEST-LANDING-CTA-INTERNAL-LINK-DIAGNOSTIC-01`

Type: generated-only read-only evidence PR.

Purpose: check CTA placement, repeated route safety, public related-link status, and whether RIASEC pillar links support course/job/major validation intent without cannibalization.

Forbidden:

- no CTA copy write;
- no frontend route change;
- no CMS link mutation.

Recommended priority: optional after FAQ/GEO.

## Option 4: GSC/seo_intel Observation Gate

PR id: `RIASEC-ZH-TEST-LANDING-GSC-SEO-INTEL-QUALITY-READBACK-01`

Type: generated-only read-only evidence PR.

Purpose: re-check whether GSC/seo_intel data has moved from fixture/blocked to live/readable and whether CTR/impression data can justify a repair.

Prerequisite: `gsc_data_quality_gate` must pass on live GSC API data.

Forbidden:

- no raw GSC payloads;
- no invented GSC metrics;
- no Search Channel enqueue;
- no search provider submission;
- no URL Truth write.

Recommended priority: later, only after data quality gate changes.
