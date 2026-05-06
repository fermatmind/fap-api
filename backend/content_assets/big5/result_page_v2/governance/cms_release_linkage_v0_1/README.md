# Big Five V2 CMS Release Linkage Policy v0.1

This package defines how Big Five V2 editorial revisions may be exported into Git-backed release candidate work.

The CMS remains an editorial governance layer only. Runtime source of truth stays with Git-backed release snapshots, the import gate, and the runtime gate. The CMS must not directly mutate runtime payloads or directly publish to runtime.
