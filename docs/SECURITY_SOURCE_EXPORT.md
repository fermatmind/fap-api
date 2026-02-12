# SECURITY SOURCE EXPORT (SEC-001)

## Only allowed source package flow
1. `bash scripts/release/export_source_zip.sh`
2. `bash scripts/release/verify_source_zip_clean.sh dist/fap-api-source.zip`

## Forbidden
- Do not use `zip -r` on workspace root.
- Do not deliver packages that include `.git`, `.env`, `vendor`, `node_modules`, runtime `storage`, logs, sqlite, or artifacts.

## Delivery artifact
- Only `dist/fap-api-source.zip` is allowed for external source delivery.
