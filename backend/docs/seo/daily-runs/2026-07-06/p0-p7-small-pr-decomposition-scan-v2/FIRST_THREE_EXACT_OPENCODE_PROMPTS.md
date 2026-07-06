# First Three Exact OpenCode Prompts

## 1. SIX-TEST-HUB-12-ROUTE-RUNTIME-READBACK-01

```text
/goal SIX-TEST-HUB-12-ROUTE-RUNTIME-READBACK-01

Repo: fap-api only.

Run a generated-only/read-only runtime readback for all 12 public test hub routes:
zh/en MBTI, Big Five, Enneagram, RIASEC, IQ, EQ.

For each route record HTTP, final URL, canonical, robots, title, meta description, H1, visible FAQ count, FAQPage JSON-LD count, CTA links, free/free-result language, visible claim boundary, private URL guard, sitemap presence, and llms.txt presence.

Output path:
backend/docs/seo/daily-runs/2026-07-06/six-test-hub-12-route-runtime-readback-01/

Required files:
EXECUTIVE_DECISION.md
TWELVE_ROUTE_RUNTIME_MATRIX.md
PRIVATE_URL_GUARD.md
NEXT_EXACT_AUTHORIZATION_PROMPTS.md
scan_manifest.json

Forbidden:
No CMS write, publish, Search, URL Truth, sitemap/llms mutation, schema/hreflang activation, fap-web edit, runtime/API mutation, DB write, or deploy.

Checks:
python3 -m json.tool backend/docs/seo/daily-runs/2026-07-06/six-test-hub-12-route-runtime-readback-01/scan_manifest.json >/dev/null
git diff --check
git diff --cached --check
```

## 2. SIX-TEST-HUB-LLMS-FULL-PRESENCE-READBACK-01

```text
/goal SIX-TEST-HUB-LLMS-FULL-PRESENCE-READBACK-01

Repo: fap-api only.

Run a generated-only/read-only llms-full presence readback for the 12 public test hub routes.
Use a bounded reliable method that can handle large llms-full output without timing out. Treat missing or partial evidence as missing, not pass.

Output path:
backend/docs/seo/daily-runs/2026-07-06/six-test-hub-llms-full-presence-readback-01/

Required files:
EXECUTIVE_DECISION.md
LLMS_FULL_ROUTE_PRESENCE_MATRIX.md
READBACK_METHOD_AND_LIMITS.md
NEXT_EXACT_AUTHORIZATION_PROMPTS.md
scan_manifest.json

Forbidden:
No llms mutation, sitemap mutation, CMS, Search, fap-web, runtime/API, DB, or deploy.

Checks:
python3 -m json.tool backend/docs/seo/daily-runs/2026-07-06/six-test-hub-llms-full-presence-readback-01/scan_manifest.json >/dev/null
git diff --check
git diff --cached --check
```

## 3. SIX-TEST-HUB-FAQ-CTA-CLAIM-GAP-MATRIX-01

```text
/goal SIX-TEST-HUB-FAQ-CTA-CLAIM-GAP-MATRIX-01

Repo: fap-api only.

Create a generated-only evidence gap matrix for FAQ, CTA, and visible claim boundaries across the 12 public test hub routes, using outputs from SIX-TEST-HUB-12-ROUTE-RUNTIME-READBACK-01 and SIX-TEST-HUB-LLMS-FULL-PRESENCE-READBACK-01.

This task requires Codex review because it touches claim-sensitive surfaces. OpenCode may prepare evidence only and must not propose public copy.

Output path:
backend/docs/seo/daily-runs/2026-07-06/six-test-hub-faq-cta-claim-gap-matrix-01/

Required files:
EXECUTIVE_DECISION.md
FAQ_CTA_CLAIM_GAP_MATRIX.md
CLAIM_REVIEW_REQUIRED_ITEMS.md
NEXT_EXACT_AUTHORIZATION_PROMPTS.md
scan_manifest.json

Forbidden:
No FAQ/title/meta/body copy write, CMS, schema, sitemap/llms, Search, fap-web, runtime/API, DB, or deploy.

Checks:
python3 -m json.tool backend/docs/seo/daily-runs/2026-07-06/six-test-hub-faq-cta-claim-gap-matrix-01/scan_manifest.json >/dev/null
git diff --check
git diff --cached --check
```
