# Big Five V2 CMS Git Sync Policy v0.1

This package defines the coexistence contract between Big Five V2 editorial CMS workflows and Git-backed runtime governance.

Git-backed release snapshots remain the runtime source of truth. CMS workflows are draft, review, preview, export, audit, and rollback governance helpers only; they cannot directly publish runtime payloads or bypass release/import/runtime gates.
