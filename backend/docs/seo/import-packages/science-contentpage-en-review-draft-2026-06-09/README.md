# Science ContentPage English Review Draft Package

Status: draft-only package. Not for CMS import, publication, sitemap, llms, footer, search submission, or social distribution until a later explicit review and controlled backend workflow.

This package creates English draft counterparts for five approved zh-CN public science/method content pages:

- `/science`
- `/item-design-notes`
- `/reliability-validity`
- `/data-privacy`
- `/common-misconceptions`

The existing English `/method-boundaries` page remains the authority and is intentionally excluded.

## Source Authority

The source text is the production CMS public API `zh-CN` content for the five scoped pages as verified on 2026-06-09. The English style target is the existing approved English `/method-boundaries` content page.

## Hard Boundaries

- No CMS mutation.
- No database writes.
- No import.
- No publish.
- No indexability.
- No sitemap, llms, footer, or search-channel exposure.
- No schema or FAQ schema eligibility.
- No invented reliability, validity, sample, norm, reviewer, item-bank, support-SLA, or deletion-SLA facts.

## Package Contents

- `manifest.json`: machine-readable draft package metadata.
- `pages/*.md`: five English ContentPage draft candidates.
- `operator_review.md`: human review notes and blockers.
- `codex_qa.md`: package QA and remaining gates.

## Expected Next Steps

1. Human English editorial review.
2. Science/legal/operator review.
3. Backend importer support for `locale=en` science ContentPage drafts.
4. Non-public CMS draft import only after explicit approval.
5. Controlled publish only after CMS review state, claim gate, SEO, and operator approval are complete.
