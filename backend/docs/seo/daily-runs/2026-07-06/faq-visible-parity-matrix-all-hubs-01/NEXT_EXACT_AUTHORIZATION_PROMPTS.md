# Next Exact Authorization Prompts

## Visible Claim-Boundary Matrix

Authorize `CLAIM-BOUNDARY-VISIBLE-SURFACE-MATRIX-01` as the next read-only evidence PR.

Allowed scope:

- Fetch the 12 public hub routes.
- Record visible claim-boundary evidence for diagnosis, treatment, hiring, admission, professional advice, career outcomes, IQ score/norms, and private URL exposure.
- Summarize route-level pass/partial/gap status.

Forbidden:

- rewriting claims
- adding FAQ content
- editing JSON-LD
- editing CMS or frontend runtime
- changing sitemap, `llms`, metadata, canonical, noindex, or deployment state

## FAQ Quality Repair Authorization Template

Use only after claim-boundary matrix is merged:

```text
Authorize <PR_ID> as a backend/CMS-authoritative scale-specific FAQ repair.

Allowed scope:
- exact scale and locale
- exact backend/CMS authority source
- exact public route(s)
- visible FAQ and matching JSON-LD generated from the same authority source

Forbidden:
- frontend fallback FAQ
- hidden schema-only FAQ
- title/meta/canonical/sitemap/llms changes
- production import/deploy without exact SHA authorization
```
