# RIASEC Explanation V2 Controlled Publish and Post-Publish Smoke

Task: `SEO-ARTICLE-RIASEC-V2-CONTROLLED-PUBLISH-01`

Decision: **Publish completed; search submission remains NO-GO because the zh frontend canonical article route returns 404.**

## Controlled Publish

Exact authorization received:

```text
I explicitly approve Codex to publish article ids 40,41 after preflight passes.
```

The controlled publish command first reran dry-run preflight. It passed with `ok=true`.

The actual controlled publish then completed with:

- `ok=true`
- `published_article_ids=[40,41]`
- `make_indexable=true`
- no command errors

No search submission, deploy, frontend deploy, private URL access, or article body/title/H1/meta/FAQ/CTA rewrite was performed.

## Post-Publish State

| Article ID | Locale | Status | Public | Indexable | Published revision | References | Media |
| ---: | --- | --- | --- | --- | ---: | ---: | --- |
| `40` | `zh` | `published` | `true` | `true` | `45` | `5` | `complete` |
| `41` | `en` | `published` | `true` | `true` | `46` | `5` | `complete` |

## Smoke Results

Backend public article API:

| Article ID | Locale query | HTTP |
| ---: | --- | ---: |
| `40` | `zh` | `200` |
| `41` | `en` | `200` |

Frontend public article detail:

| Article ID | Locale segment | HTTP | Decision |
| ---: | --- | ---: | --- |
| `40` | `zh` | `404` | blocked |
| `41` | `en` | `200` | passed |

The likely blocker is locale contract mismatch: article `40` is published with backend locale `zh`, while the existing Chinese canary article uses backend locale `zh-CN` and the fap-web zh route appears to expect `zh-CN`.

## Search Submission Gate

Search submission remains blocked.

Reasons:

- zh frontend canonical route is 404
- sitemap/llms enumeration was not consistently converged immediately after publish
- search submission still requires separate exact authorization

## Next Step

Run a narrow locale/API contract correction before any search submission. Do not rewrite article content.
