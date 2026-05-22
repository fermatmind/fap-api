# Content Ops / Claim / Link Ops Readiness

Task: `CONTENT-OPS-CLAIM-LINK-OPS-READINESS`

This contract defines future read-only `/ops/seo` display readiness for Content Ops, Internal Link, and Claim Lint outputs. This PR is docs/generated/test only. It does not implement Filament UI, add action buttons, add write controls, mutate CMS, create internal links, rewrite content, enqueue Search Channel rows, submit URLs, expose Metabase, run migrations, enable scheduler, run collectors, modify fap-web, or deploy.

## Future /ops/seo Sections

The future read-only dashboard may display:

- Content publish rehearsal summary
- planned observation event counts
- draft blocked from sitemap / `llms.txt` / search counters
- internal link graph coverage
- missing entity key count
- `legacy_unpaired` count
- unsafe fallback link source count
- claim lint `safe` / `needs_review` / `blocked` counts
- P0 / P1 / P2 / P3 claim issue summary
- content ops sidecar warnings

## Read-only Inputs

Allowed future inputs are read-only summaries from:

- Content publish rehearsal dry-run output
- Internal link graph dry-run output
- Chinese claim linter fixture/CI or approved candidate-package output
- Observation Governance planned event summaries
- Entity key / translation group coverage summaries

The dashboard must not become content, link, claim, sitemap, Search Channel, crawler, or Metabase authority.

## Hard Stops

The future `/ops/seo` display must include no publish button, no rewrite button, no internal link creation button, no Search Channel enqueue button, no submit URL button, no scheduler controls, no collector controls, no raw SQL, no Metabase iframe or proxy, no raw payload display, no raw crawler logs, and no CMS write controls.

Hard stop checklist:

- publish button
- rewrite button
- internal link creation button
- Search Channel enqueue button
- submit URL button
- scheduler controls
- collector controls
- raw SQL
- Metabase iframe or proxy
- raw payload display
- raw crawler logs
- CMS write controls

## Authority Boundary

CMS/backend owns content, metadata, publish state, canonical, robots/noindex, and claim boundaries. The internal link graph is backend/CMS authoritative. fap-web deterministically renders public runtime but must not become fallback authority. `/ops/seo` is an operational view only.

## Next Task

Next task: `CONTENT-OPS-CLAIM-LINK-CLOSEOUT`
