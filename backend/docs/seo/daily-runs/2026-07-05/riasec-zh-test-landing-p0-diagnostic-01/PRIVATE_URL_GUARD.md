# Private URL Guard

Target: `/zh/tests/holland-career-interest-test-riasec`

## Verdict

Status: `PASS_READ_ONLY`

No private URLs were observed in the extracted public page links.

## Patterns Checked

The extracted links were reviewed for private or sensitive URL patterns including:

- `token`
- `attempt`
- `order`
- `payment`
- `result`
- `report`
- `claim`
- `private`
- `share_token`

Observed hit count: `0`

## Public Routes Observed

Accepted public CTA routes:

- `/zh/tests/holland-career-interest-test-riasec/take?form=riasec_60`
- `/zh/tests/holland-career-interest-test-riasec/take?form=riasec_140`

Accepted public internal-link route classes:

- `/zh/articles/...`
- `/zh/tests/...`
- `/zh/tests`
- `/zh/articles`
- `/zh/career`
- `/zh/careers`

## Guardrail

Future SEO/GEO work must keep private assessment results, attempts, reports, orders, claims, payment flows, and tokenized share URLs out of:

- sitemap;
- llms surfaces;
- JSON-LD;
- public internal links;
- generated reports intended for public SEO/GEO evidence;
- analytics logs that expose identifiable private URLs.

No private URL repair is required in this PR.
