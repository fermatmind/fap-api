# Big Five Repair Log Template v0.1

Package: B5-CONTENT-0 v0.1.1  
Runtime use: not_runtime  
Production use allowed: false

This is a template. It contains no user-facing Big Five body copy.

| issue_id | severity | asset_key | finding | repair_action | before | after | reviewer | status |
|---|---|---|---|---|---|---|---|---|
| T-000 | P0/P1/P2 | example_asset_key | Example finding only | Example repair action | n/a | n/a | reviewer_name | open/closed |

## Hard gate

`ready_for_pilot=true` is not allowed until:

- coverage QA passed
- safety QA passed
- editorial QA passed
- mapping QA passed
- rendered preview QA passed
- repair log all closed
- no P0 blockers

Default: `ready_for_pilot=false`, `production_use_allowed=false`.
