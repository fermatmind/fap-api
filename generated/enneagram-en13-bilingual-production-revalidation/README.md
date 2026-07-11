# EN13 bilingual production revalidation

Verdict: **GO**

This artifact records the read-only production closeout for
`ENNEAGRAM-EN13-BILINGUAL-PRODUCTION-REVALIDATION-01` after the English 13-page
publish and sitemap-source cache warm. It did not deploy code, mutate CMS data,
publish assets, run a cache-warm command, release LLM/search eligibility, or
submit URLs to search providers.

## Final acceptance

- Public API: 26/26 responses are HTTP 200, public, published, `index,follow`,
  index eligible, sitemap eligible, and schema-runtime eligible.
- Web runtime: 26/26 responses are HTTP 200 with `index, follow`, exact
  canonical, and complete `en` / `zh-CN` / `x-default` hreflang triplets.
- FAQ: 112/112 API FAQ items are visible; 112/112 match FAQPage entities; all
  26 pages emit FAQPage.
- Internal links: 108/108 CMS-authoritative internal links render as anchors.
- Sitemap: the bilingual Enneagram personality-profile set is exactly 26 URLs,
  with no missing or unexpected profile URLs.
- LLM/Search hold: `llms.txt` and `llms-full.txt` contain 0 bilingual Enneagram
  profile URLs; no public payload reports a true search-release flag.
- Private boundary: 26/26 HTML responses are clean of attempt/report/result/
  order/payment path leakage.
- Stability gate: after resolving first-read stale metadata, three consecutive
  complete 26-page scans returned GO.

## First-read cache observation

The initial read-only scans intermittently observed the pre-publish
`noindex, follow, noarchive, nocache` metadata on individual English routes,
while the same route's public API already returned `published`, `index,follow`
and sitemap membership was correct. The frontend Enneagram asset adapter uses
the shared Next data-cache policy with `revalidate: 300`; the first request for
an untouched stale cache key can serve the old value while revalidation occurs.
No cache-warm command was run. Ordinary read-only requests refreshed those keys,
after which three consecutive full scans passed 26/26.

This observation does not justify weakening the fail-closed metadata fallback.
The repeatable scanner remains in `revalidate.mjs` so future publish SOPs can
require convergence rather than relying on a single snapshot.

## Reproduce

```bash
node generated/enneagram-en13-bilingual-production-revalidation/revalidate.mjs
jq '{verdict,summary,surface_checks}' generated/enneagram-en13-bilingual-production-revalidation/production-revalidation.json
```

The JSON artifact stores only status/count/hash evidence and safe public paths;
it does not store content bodies, secrets, private identifiers, or server
topology.
