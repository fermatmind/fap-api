# Blocked or Unverified Assumptions

This file records what was not proven by the diagnostic and must not be treated as completed evidence.

## Blocked

### GSC/seo_intel Quality

Status: `blocked`

Reason:

- `backend/docs/seo/generated/gsc-data-quality-gate.v1.json` records `data_origin=fixture`.
- `data_quality_gate_status=blocked`.
- `opportunity_queue_eligible=false`.

Implication: no CTR, impression, average-position, or query/page repair should be triggered from current GSC/seo_intel evidence.

## Unverified

### Visible FAQ vs FAQPage JSON-LD Parity

Status: `unverified`

Reason:

- JSON-LD FAQPage has four questions.
- Simple visible-heading extraction did not find those same questions after the FAQ heading.
- The extraction method may miss accordion/button/non-heading rendered FAQ labels.

Implication: open a separate read-only DOM parity readback before any FAQPage schema or visible FAQ repair.

### CMS Live Source Row

Status: `not_checked`

Reason: this diagnostic did not access production CMS databases or write-capable admin surfaces.

Implication: backend authority is confirmed from repo evidence and public runtime, but exact production CMS row provenance was not audited.

### GA / Product Event Impact

Status: `not_checked`

Reason: this diagnostic did not query GA, product analytics, payment events, or conversion funnels.

Implication: CTA performance claims are not made here.

### GSC Query/Page Metrics

Status: `not_checked`

Reason: GSC data quality gate is blocked and raw GSC payload use is forbidden.

Implication: no formal CTR/impression/average-position metric is included.

### Screenshot-Derived Metrics

Status: `not_used`

Reason: screenshot-derived metrics are explicitly forbidden as formal evidence in this goal.

Implication: all runtime evidence in this diagnostic comes from public HTML/readback and repo files, not screenshot measurement.

## Non-Goals Confirmed

This PR does not prove or perform:

- CMS write/publish/import;
- production deploy;
- runtime/API mutation;
- sitemap/llms/schema/hreflang mutation;
- title/meta/FAQ copy update;
- Search Channel enqueue;
- search provider submission;
- frontend fallback content.
