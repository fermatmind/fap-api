# MBTI Main FAQ Production Runtime Readback 02

Decision: `RUNTIME_FAQ_PARITY_PASS`

Observed at: 2026-07-05T10:07:27Z

The canonical production zh MBTI test landing URL now renders the backend API's 8 FAQ entries without a cache-bust query.

Pass criteria:

- API FAQ = 8: pass
- visible FAQ = 8: pass
- FAQPage JSON-LD = 8: pass
- visible questions == JSON-LD questions: pass
- private URL boundary: pass

Cache/revalidation repair is not needed from current evidence. The prior stale 4-entry canonical HTML has been replaced by an 8-entry cache HIT response.

Next eligible task: `MBTI-MAIN-FAQ-D0-OBSERVATION-BASELINE-01`.

No runtime code, CMS content, sitemap, llms, Search Channel, deployment, production import, or database mutation was changed.
