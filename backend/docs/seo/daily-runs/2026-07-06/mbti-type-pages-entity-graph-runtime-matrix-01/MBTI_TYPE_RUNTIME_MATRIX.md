# MBTI Type Pages Entity Graph Runtime Matrix

Date: 2026-07-06
Scope: read-only runtime matrix for 16 MBTI base type profiles

## Route Shape

Observed route shape:

- Base path such as `/zh/personality/intj` resolves to `/zh/personality/intj-a`.
- Public profile path shape is `/zh/personality/{type}-a` and `/en/personality/{type}-a`.
- Backend API path shape is `/api/v0.5/personality/{TYPE}?locale={locale}`.

## Matrix

| Type | zh public route | zh status | en public route | en status | API zh-CN | API en |
| --- | --- | ---: | --- | ---: | ---: | ---: |
| INTJ | `/zh/personality/intj-a` | 200 | `/en/personality/intj-a` | 200 | 200 | 200 |
| INTP | `/zh/personality/intp-a` | 200 | `/en/personality/intp-a` | 200 | 200 | 200 |
| ENTJ | `/zh/personality/entj-a` | 200 | `/en/personality/entj-a` | 200 | 200 | 200 |
| ENTP | `/zh/personality/entp-a` | 200 | `/en/personality/entp-a` | 200 | 200 | 200 |
| INFJ | `/zh/personality/infj-a` | 200 | `/en/personality/infj-a` | 200 | 200 | 200 |
| INFP | `/zh/personality/infp-a` | 200 | `/en/personality/infp-a` | 200 | 200 | 200 |
| ENFJ | `/zh/personality/enfj-a` | 200 | `/en/personality/enfj-a` | 200 | 200 | 200 |
| ENFP | `/zh/personality/enfp-a` | 200 | `/en/personality/enfp-a` | 200 | 200 | 200 |
| ISTJ | `/zh/personality/istj-a` | 200 | `/en/personality/istj-a` | 200 | 200 | 200 |
| ISFJ | `/zh/personality/isfj-a` | 200 | `/en/personality/isfj-a` | 200 | 200 | 200 |
| ESTJ | `/zh/personality/estj-a` | 200 | `/en/personality/estj-a` | 200 | 200 | 200 |
| ESFJ | `/zh/personality/esfj-a` | 200 | `/en/personality/esfj-a` | 200 | 200 | 200 |
| ISTP | `/zh/personality/istp-a` | 200 | `/en/personality/istp-a` | 200 | 200 | 200 |
| ISFP | `/zh/personality/isfp-a` | 200 | `/en/personality/isfp-a` | 200 | 200 | 200 |
| ESTP | `/zh/personality/estp-a` | 200 | `/en/personality/estp-a` | 200 | 200 | 200 |
| ESFP | `/zh/personality/esfp-a` | 200 | `/en/personality/esfp-a` | 200 | 200 | 200 |

## Repository Evidence

- OpenAPI snapshot exposes `/api/v0.5/personality/{type}`, `/api/v0.5/personality/{type}/seo`, and `/api/v0.5/personality/{type}/desktop-clone`.
- `PersonalityProfile` model includes MBTI type-code allowlist.
- `PersonalityController` includes personality profile payload and start-test links back to `/tests/mbti-personality-test-16-personality-types`.
- Existing generated/import evidence includes MBTI type media and MBTI 64 comparison content packages.

## Runtime Evidence Method

Commands used:

```bash
curl -L -s -o /dev/null -w '%{http_code} %{url_effective}' https://www.fermatmind.com/zh/personality/intj
curl -L -s -o /dev/null -w '%{http_code}' 'https://api.fermatmind.com/api/v0.5/personality/INTJ?locale=zh-CN'
```

The batch check repeated equivalent public zh/en and API zh-CN/en checks for all 16 base MBTI type codes.

## Remaining Gaps

This matrix does not close:

- sitemap/llms inclusion or exclusion correctness;
- canonical/hreflang parity for all profile routes;
- visible internal-link graph completeness;
- claim boundary review for every profile page;
- MBTI-A/T route graph beyond the observed `*-a` base public route shape;
- cross-type comparison graph completion.

Those require separate readback or implementation PRs.
