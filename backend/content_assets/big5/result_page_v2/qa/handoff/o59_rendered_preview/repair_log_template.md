# Repair Log Template｜B5-B1 O59 Rendered Preview Contract Test

| issue_id | severity | surface | finding | expected | actual | repair_action | owner | status |
|---|---|---|---|---|---|---|---|---|
| O59-RP-001 | P0 | result_page_desktop | Example: English compact subtitle rendered | Chinese canonical section copy | A compact overview... | Route fixture through V2 preview path / remove fallback source | frontend | open |

## Status rules

- P0 must be closed before pass.
- P1 must be closed or explicitly accepted before preview pass.
- pending_surface is not a pass.
- Missing harness must be recorded as pending_surface, not silently skipped.


## v0.1.1 repair categories

- Manifest file list / file_count mismatch
- Duplicate surfaces in visible text matrix
- Over-broad raw substring assertion for `all`
- Missing Codex output arrays for tested/pending/passed/failed surfaces
