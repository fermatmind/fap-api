# Big Five V2 Immutable Release Snapshot

This package records the first Big Five V2 production-governance release snapshot.

It is immutable, references-only, and not runtime. It does not rewrite payloads, enable production, change scoring, or modify content bodies.

## Files

- `big5_v2_release_snapshot_rc_0_1.json` records the immutable snapshot envelope.
- `manifest.json` indexes the snapshot and records the file hash.
- `SHA256SUMS` records reproducible package hashes.

## Production State

- `production_use_allowed`: false
- `ready_for_production`: false
- Runtime default: disabled
- Rollout state: not started
