# Next Exact Authorization Prompts

Use these only after the remaining readback cards confirm first-screen, FAQ parity, and visible claim-boundary state.

## First-Screen Readback

Authorize `CORE-HUB-FIRST-SCREEN-ANSWER-BLOCK-READBACK-01` as a read-only runtime evidence PR.

Scope:

- Fetch the 12 public hub routes.
- Record whether the first screen contains a visible direct answer for the test, free-result expectation, claim boundary, and primary CTA.
- Do not mutate runtime, copy, metadata, CMS, schema, sitemap, or `llms`.

## FAQ Parity Readback

Authorize `FAQ-VISIBLE-PARITY-MATRIX-ALL-HUBS-01` as a read-only runtime evidence PR.

Scope:

- Compare visible FAQ questions with FAQPage JSON-LD `mainEntity` questions on the 12 public hub routes.
- Record count and question parity.
- Do not create fallback FAQ or edit JSON-LD.

## Claim Boundary Matrix

Authorize `CLAIM-BOUNDARY-VISIBLE-SURFACE-MATRIX-01` as a read-only evidence PR.

Scope:

- Record visible claim-boundary evidence for diagnosis, hiring, admission, financial/legal decisioning, career outcome, IQ score/norm, and private URL exposure.
- Do not strengthen or rewrite claims.

## Repair Authorization Template

When a specific repair is ready, use this shape:

```text
Authorize <PR_ID> as a backend/CMS-authoritative repair PR.

Allowed scope:
- <exact authority source>
- <exact route set>
- <exact fields/blocks>
- <exact local checks>

Forbidden:
- frontend fallback public content
- hidden schema-only repair
- sitemap/llms mutation unless separately authorized
- production import/deploy without exact SHA authorization
```
