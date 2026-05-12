# Career Surface Readiness Audit

AUDIT-8 adds a read-only surface readiness layer for the Career 2786 canonical eligibility audit train. It validates backend API surface artifacts and can optionally validate synthetic live HTML when a caller supplies a base URL and HTML content.

AUDIT-8 does not fetch production HTML by itself, deploy, modify fap-web, mutate DB state, apply rollout, backfill data, or implement the full canonical eligibility audit command.

## Inputs

The auditor accepts:

- AUDIT-2 normalized plan rows, array rows, or slug lists
- expected locales such as `["en", "zh"]`
- API artifact arrays with rows under `items`, `rows`, `api.items`, or `api.rows`
- optional live HTML strings keyed by `{slug}|{locale}`
- optional base URL when live HTML mode is requested

## Checks

API artifact mode checks:

- API canonical path matches `/{locale}/career/jobs/{slug}`
- API surface is indexable and does not expose noindex

Optional live HTML mode checks:

- base URL/context is present
- verifier HTML is supplied
- live canonical path matches expected self path
- live robots policy does not include noindex
- attributable career CTA marker is present
- API and live canonical paths agree

Missing live verifier/context is reported as `unverified`, not pass.

## Issue Reasons

- `api_canonical_not_self`
- `api_noindex_present`
- `live_canonical_not_self`
- `live_noindex_present`
- `cta_missing_or_unattributed`
- `surface_verifier_missing`
- `validator_context_missing`
- `unexpected_exposure`
- `real_surface_mismatch`

## AUDIT-1 Layer Status

Each row emits an AUDIT-1-compatible `surface` layer status:

```json
{
  "layer": "surface",
  "status": "pass",
  "reasons": [],
  "evidence": [
    {
      "slug": "actuaries",
      "locale": "en"
    }
  ],
  "source": "surface_artifacts"
}
```

Rows with missing optional live verifier context emit `status=unverified`. Rows with API or verified live mismatches emit `status=blocked`.

## Non-Goals

AUDIT-8 does not:

- change frontend code
- perform production fetches
- deploy
- mutate backend state
- run rollout apply/backfill/rollback/quarantine
- generate expansion manifests
- claim 2786 readiness

## Consumption By AUDIT-9+

AUDIT-9 should wire AUDIT-8 as an optional surface layer in the read-only command. Live HTML mode must remain opt-in and require explicit base URL/context.
