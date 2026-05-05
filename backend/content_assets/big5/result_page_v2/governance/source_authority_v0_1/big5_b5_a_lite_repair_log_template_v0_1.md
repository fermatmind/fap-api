# B5-A-lite Repair Log Template v0.1

本文件是后续 B5-A-lite / B5-B1 governance 与 readiness 复核时使用的 repair log 模板。当前包不包含实际 repair items。

| issue_id | severity | file | field_or_section | finding | repair_action | before | after | reviewer | status |
|---|---|---|---|---|---|---|---|---|---|
| B5A-001 | P0/P1/P2 | path/to/file | field | describe issue | describe repair | before state | after state | reviewer | open/closed |

## Status rules

- `open`: still blocks readiness if P0/P1.
- `closed`: fixed and revalidated.
- `deferred`: allowed only for non-blocking P2/P3 issues with explicit next batch.

## Pilot gate reminder

B5-B1 cannot become ready for pilot unless:

- coverage QA passed;
- safety QA passed;
- editorial QA passed;
- mapping QA passed;
- rendered preview QA passed;
- repair log all closed;
- no P0 blockers.
