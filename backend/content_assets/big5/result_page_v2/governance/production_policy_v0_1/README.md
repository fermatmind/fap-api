# Big Five V2 Production Governance Policy Schema

This package defines the production governance policy schema for Big Five V2.

It is a schema and validation reference only. It does not wire runtime behavior, does not approve any release for production, and does not change scoring or content bodies.

## Files

- `big5_v2_production_governance_policy_v0_1.json` defines the required governance decision fields and default fail-closed production state.
- `manifest.json` identifies the package and records that production remains blocked.
- `SHA256SUMS` records reproducible file hashes for the package.

## Production State

- `production_use_allowed`: false
- `ready_for_production`: false
- Runtime default: disabled
- Rollout state: not started
