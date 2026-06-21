# RIASEC Result Page Source Ledger v0.1

Status: `RIASEC-RESULT-SOURCE-LEDGER-01`

This directory contains the first source ledger for the Holland/RIASEC result page asset agent. It does not generate assets, import CMS data, change runtime behavior, or open pilot/production gates.

## Files

- `source_ledger_template.json`: required claim-row shape for future source evidence.
- `riasec_result_source_ledger_v0_1.json`: first evidence ledger covering public O*NET/DOL/Holland method references, internal RIASEC docs, and existing repository asset-pack checksums.

## Use

Future agent runs may use these rows only as staging references. A generated claim is allowed only when it has a matching source row with permitted use, limitation, disallowed use, and `claim_status=approved_for_staging_reference` or a stricter reviewer-approved status.

## Boundaries

- `runtime_use=staging_only`
- `production_use_allowed=false`
- `ready_for_runtime=false`
- `ready_for_production=false`
- no CMS writes
- no runtime wrapper enablement
- no frontend fallback
- no private score, raw score, vector, percentile, route, or share leak
