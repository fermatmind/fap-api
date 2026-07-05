# Next Exact Authorization Prompts

Use one prompt at a time. Do not combine these scopes.

## Recommended Next Prompt

```text
Authorize Codex to open one generated-only read-only PR in fap-api:

RIASEC-ZH-TEST-LANDING-FAQ-PARITY-READBACK-01

Scope:
- Public runtime readback for /zh/tests/holland-career-interest-test-riasec.
- Extract rendered visible FAQ questions with a DOM-capable method.
- Extract FAQPage JSON-LD mainEntity questions.
- Compare visible FAQ questions against JSON-LD questions.
- Record whether both surfaces share the same data source or whether a repair PR is needed.

Allowed output path:
backend/docs/seo/daily-runs/2026-07-05/riasec-zh-test-landing-faq-parity-readback-01/

Forbidden:
- no CMS write
- no CMS publish
- no content import
- no schema/hreflang/sitemap/llms mutation
- no frontend fallback content
- no fap-web edit
- no deploy
- no runtime/API mutation
- no FAQ/title/meta copy write
- no Search Channel or search provider submission

Required checks:
- python3 -m json.tool backend/docs/seo/daily-runs/2026-07-05/riasec-zh-test-landing-faq-parity-readback-01/scan_manifest.json >/dev/null
- git diff --check
- git diff --cached --check
```

## Optional Follow-Up Prompt After Parity Is Known

```text
Authorize Codex to open one generated-only backend authority dry-run PR in fap-api:

RIASEC-ZH-TEST-LANDING-FAQ-GEO-AUTHORITY-DRYRUN-01

Scope:
- Propose, but do not write, backend/CMS-authority FAQ and GEO answer-surface changes for /zh/tests/holland-career-interest-test-riasec.
- Cover direct free-test intent, 60题 vs 140题 difference, exploration-signal boundary, and course/job-activity/major-validation framing.
- Preserve claim boundaries: no precise career recommendation, no guaranteed major/admission/hiring/salary/career outcome, no unsupported psychometric validity/norm claims.
- Output exact future repair authorization prompts only if needed.

Allowed output path:
backend/docs/seo/daily-runs/2026-07-05/riasec-zh-test-landing-faq-geo-authority-dryrun-01/

Forbidden:
- no CMS write
- no CMS publish
- no content import
- no runtime/API mutation
- no final title/meta/FAQ copy write
- no schema/hreflang/sitemap/llms mutation
- no fap-web edit
- no deploy
- no Search Channel or search provider submission

Required checks:
- python3 -m json.tool backend/docs/seo/daily-runs/2026-07-05/riasec-zh-test-landing-faq-geo-authority-dryrun-01/scan_manifest.json >/dev/null
- git diff --check
- git diff --cached --check
```

## Later Prompt When GSC Quality Gate Passes

```text
Authorize Codex to open one generated-only read-only PR in fap-api:

RIASEC-ZH-TEST-LANDING-GSC-SEO-INTEL-QUALITY-READBACK-01

Scope:
- Verify whether backend GSC/seo_intel quality gate for /zh/tests/holland-career-interest-test-riasec is passable using approved read models.
- Use only sanitized/hash/aggregate evidence.
- Decide whether CTR/impression evidence justifies a future repair prompt.

Forbidden:
- no live GSC call unless separately authorized by the existing backend GSC live-read SOP
- no raw query or raw URL payloads
- no CMS write
- no Search Channel enqueue
- no search provider submission
- no URL Truth write
- no runtime/API mutation
- no deploy
```
