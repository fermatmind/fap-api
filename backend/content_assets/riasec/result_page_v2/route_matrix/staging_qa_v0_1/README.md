# RIASEC Result Page V2 Route Matrix Staging QA Seed

- Task: `RIASEC-RESULT-ROUTE-MATRIX-GOLDEN-CASE-QA-01`
- Runtime use: `staging_only`
- Production use allowed: `false`
- Ready for runtime: `false`
- Ready for production: `false`

This directory contains a small QA seed used to test route-matrix and golden-case report mechanics. It is not a complete route matrix and is not wired to runtime selectors.

The seed records seven canonical groups from the selector QA policy:

- clear primary code
- near-tie pair
- top3 chain
- low quality
- share-safe
- norm unavailable
- route miss

Only the share-safe row resolves to an existing selector candidate from `share_safety_pilot_20260621T1555Z`. Other route-specific rows intentionally fail closed until a full selector asset package exists.
