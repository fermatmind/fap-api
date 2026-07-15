# Enneagram Public Authority V2 integrity gate

`ENNEAGRAM-PUBLIC-AUTHORITY-V2-INTEGRITY-GATE-02` adds a deterministic, zero-write validation boundary for the 116 public Enneagram pages frozen by PR01.

## Authority and input

- Authority remains the backend CMS/public API. This gate validates a frozen read-only scorecard; it does not become runtime or editorial authority.
- Default input: `docs/seo/personality/enneagram-authority-v2/enneagram-public-authority-v2-benchmark-01/production-scorecard.json` from the backend working directory.
- Exact scope: 58 identities × 2 locales = 116 pages: 2 hub, 6 center, 18 core-type, 36 wing, and 54 instinctual-subtype rows.

## Fail-closed checks

The gate rejects any of the following:

- missing, duplicate, or out-of-taxonomy identity/locale rows;
- route, HTTP-200, soft-404, effective URL, canonical, or exact `en` / `zh-CN` / English `x-default` hreflang drift;
- references to private attempt, report, result, order, payment, checkout, account, user, or identifiable query targets;
- a private-boundary row marked unsafe or containing violations;
- a model/agent state represented as named human review, an unsupported review state, or synthesized V2 revision pointers;
- envelope claims other than the frozen 116-page, zero-human-review, zero-production-write truth.

## Run

From `backend/`:

```bash
php artisan personality:enneagram-authority-v2-integrity-gate --json
```

The command returns success only when every gate passes. It emits a report to stdout and always reports these side-effect flags as false:

- `writes_committed`
- `cms_write_attempted`
- `database_mutation_attempted`
- `indexability_mutation_attempted`
- `search_submission_attempted`

## Repository rule impact

This PR adds a backend-owned validation service and CLI only. It does not change content ownership, public API output, publishing, production data, review truth, sitemap/llms membership, or frontend fallback behavior.
