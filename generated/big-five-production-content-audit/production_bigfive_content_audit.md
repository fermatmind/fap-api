# Big Five Production Content Audit

Scanned at: 2026-07-05T16:03:47.219Z

## Verdict

- Status: `pass`
- Production public API Big Five rows: `34` total, `17` zh-CN, `17` en
- v2 package rows visible in public API: `0`
- Title-only section rows visible in public API: `34`
- Schema runtime eligible rows visible in public API: `0`
- Index / sitemap / llms eligible rows visible in public API: `0` / `0` / `0`

## Scope

- Read-only public API scan only.
- No CMS write, production import, publish, indexability release, sitemap/llms release, JSON-LD runtime release, or deploy was attempted.
- Direct production DB/CMS readback should use `php artisan personality:big-five-production-content-audit --target-env=production --json` on the production runtime.

## Evidence Endpoints

- zh-CN: https://api.fermatmind.com/api/v0.5/personality-content-assets?framework=big_five&locale=zh-CN&per_page=100
- en: https://api.fermatmind.com/api/v0.5/personality-content-assets?framework=big_five&locale=en&per_page=100

## Sample Canonical Paths

- /zh/personality/big-five/agreeableness
- /zh/personality/big-five/conscientiousness
- /zh/personality/big-five/extraversion
- /zh/personality/big-five/neuroticism
- /zh/personality/big-five/openness
- /en/personality/big-five/agreeableness
- /en/personality/big-five/conscientiousness
- /en/personality/big-five/extraversion
- /en/personality/big-five/neuroticism
- /en/personality/big-five/openness
