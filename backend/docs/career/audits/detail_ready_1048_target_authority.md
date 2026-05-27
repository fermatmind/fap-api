# Career Detail-Ready 1048 Target Authority

`CareerDetailReadyTargetAuthority` defines the backend authority semantics for
the `detail_ready_1048` target.

This target means 1048 product-visible Career detail pages, not 2786 partition
accounting. It preserves the current public 30 cohort, identifies the planned
1018 delta, and keeps raw/excluded occupation assets out of publication
authority.

## Guardrails

- No production mutation, CMS mutation, candidate-prep apply, rollout apply, or
  deploy is allowed by this target authority.
- `software-developers` remains a manual-hold slug and must not be force-enabled.
- CN proxy rows remain outside product-visible detail-page authority and must
  preserve noindex/noncanonical policy.
- A visible 1048 claim requires dataset, jobs API, detail-ready,
  public-detail-indexable, locale-row, release-gate, sitemap, and llms evidence.
- 2786 partition accounting never proves a 1048 visible-detail claim.

## Acceptance Shape

For the default `en` + `zh` locale policy, acceptance must eventually prove:

- dataset member count: 1048
- career jobs API item count: 1048
- detail-ready count: 1048
- public detail indexable count: 1048
- published locale rows: 2096
- release gate pass rows: 2096
- no noindex, 404, or redirect-source URLs in sitemap / llms surfaces
