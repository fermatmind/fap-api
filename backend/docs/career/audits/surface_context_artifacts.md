# Career Surface Context Artifacts

REPAIR-SURFACE-CONTEXT-1 adds read-only surface context artifact support to `career:audit-canonical-eligibility`.
CTX-SURFACE-EXPORT-1 adds the matching read-only producer command.

The artifact lets the audit consume previously generated or locally verified surface evidence without running a production live crawl and without touching `fap-web`.

## Command Usage

Generate a surface context artifact without crawling live production HTML:

```bash
php artisan career:export-canonical-eligibility-surface-context \
  --public-resolution-plan=/tmp/career_2786_public_resolution_plan_from_d23b.json \
  --output=/tmp/career_2786_surface_context.json \
  --locales=en,zh \
  --json
```

Planner-only mode is intentionally conservative. It writes one row per planner slug and locale, but sets
`surface_verified=false` with `surface_artifact_missing` and `surface_unverified` issues. That removes the generic
`surface_context_missing` blocker when the audit consumes the artifact, but it does not prove live or API surface readiness.

Consume the artifact:

```bash
php artisan career:audit-canonical-eligibility \
  --scope=all \
  --public-resolution-plan=/tmp/career_2786_public_resolution_plan_from_d23b.json \
  --surface-context=/tmp/career_2786_surface_context.json \
  --json
```

## Shape

```json
{
  "schema_version": "career_surface_context.v1",
  "source": {
    "type": "read_only_surface_export",
    "generated_at": "2026-05-13T00:00:00Z",
    "environment": "local|production|unknown"
  },
  "rows": [
    {
      "canonical_slug": "actuaries",
      "locale": "en",
      "api_canonical_path": "/en/career/jobs/actuaries",
      "api_indexable": true,
      "live_canonical_path": null,
      "live_robots_policy": null,
      "cta_present": null,
      "surface_verified": false,
      "surface_mode": "planner_only",
      "issues": [
        "surface_artifact_missing",
        "surface_unverified"
      ],
      "evidence": {}
    }
  ]
}
```

Rows are keyed by `canonical_slug` and `locale`. `api_indexable` is required and must be boolean. `api_canonical_path` is optional but a missing or mismatched value remains a real surface readiness blocker.
`surface_verified=false` is explicit unverified evidence. It is not a pass condition.

## Read-Only Behavior

`--surface-context` only reads JSON from the supplied path. It does not:

- crawl live production HTML
- deploy or modify `fap-web`
- mutate DB state
- apply rollout, backfill, rollback, or quarantine

## Reason Semantics

- `surface_context_missing`: no surface context mode or artifact was supplied.
- `surface_context_file_missing`: an explicit artifact path was invalid.
- `surface_context_json_invalid`: an explicit artifact file was not valid JSON.
- `surface_context_rows_missing`: the artifact did not contain a `rows` list.
- `surface_context_row_missing`: a slug/locale expected by the planner had no artifact row.
- `surface_artifact_missing`: the producer had no concrete API/static/live surface artifact for the row.
- `surface_unverified`: the row is present but explicitly unverified.
- `api_canonical_not_self`, `api_noindex_present`, and live HTML reasons remain real surface blockers when supported by artifact evidence.

This separates missing/unverified surface context from real surface mismatch evidence.

## Non-Goals

This producer does not:

- run production live crawl
- touch `fap-web`
- mutate DB state
- deploy
- run rollout, backfill, rollback, quarantine, or 80 readiness

Future live HTML verification must be separately approved with a base URL, rate limit, and output path. That approval is not
implied by this read-only artifact producer.
