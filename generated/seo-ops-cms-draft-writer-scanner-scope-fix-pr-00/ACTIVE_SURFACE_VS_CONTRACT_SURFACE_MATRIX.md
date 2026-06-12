# Active Surface vs Contract Surface Matrix

## Active Import Surfaces

These continue to block old routes, private URLs, and sensitive tokenized URLs:

| Surface | Scanner behavior |
| --- | --- |
| `pages/*.md` frontmatter | Scanned as active metadata. |
| `pages/*.md` body markdown | Scanned as active article body. |
| `cms/CMS_FIELDS_*.json` | Scanned as active CMS field payload. |
| `cms/CMS_IMPORT_DRAFT_*.json` | Scanned as active CMS draft payload. |
| `manifest.json` page entries | Scanned as active page metadata. |
| `contracts/DYNAMIC_CTA_CONTRACT.json` active CTA/link fields | Scanned after removing policy-only forbidden fields. |
| `contracts/INTERNAL_LINK_PLAN.json` active link fields | Scanned after removing policy-only forbidden fields. |
| `contracts/PUBLIC_CANONICAL_ROUTE_CONTRACT.json` | Scanned as public route authority input. |

Active surfaces block:

- `/tests/big-five-personality-test`
- `/result`
- `/results`
- `/orders`
- `/order`
- `/share`
- `/pay`
- `/payment`
- `/history`
- `/take`
- tokenized sensitive query URLs using `result_id`, `order_id`, `payment_id`, `token`, `score`, `user_id`, or `report_id`

## Contract / Policy Surfaces

These are checked by context-aware contract integrity rules:

| Surface | Allowed context | Blocking context |
| --- | --- | --- |
| `contracts/ROUTE_ALIAS_CONTRACT.json` | Old Big Five route as alias key only, with canonical OCEAN route value. | Old Big Five route as alias value or any non-alias occurrence. |
| `contracts/PRIVATE_URL_GUARD.json` | Private routes and sensitive keys inside forbidden guard fields. | Private routes or sensitive keys inside allowed route fields or active target fields. |
| `contracts/DYNAMIC_CTA_CONTRACT.json` | Sensitive keys inside `forbidden_tracking_params`. | Sensitive keys inside `allowed_tracking_params` or active CTA params. |
| `review/claim_gate.md` | Review-only forbidden claim context. | Not scanned as article body. |

## Preflight Output

The command response now includes:

- `active_surface_guard_scan.status`
- `active_surface_guard_scan.error_count`
- `contract_integrity_scan.status`
- `contract_integrity_scan.error_count`

Primary new error codes:

- `old_big_five_route_found_in_active_surface`
- `private_route_found_in_active_surface`
- `sensitive_query_key_found_in_active_surface`
- `route_alias_contract_invalid`
- `private_url_guard_contract_invalid`
- `dynamic_cta_forbidden_params_contract_invalid`
