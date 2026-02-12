# INCIDENT RELEASE LEAK (SEC-001)

1. Immediately rotate APP_KEY, payment webhook secrets, and all leaked tokens/keys.
2. Inventory and revoke all historical outbound ZIPs and CI artifacts; invalidate shared links.
3. Only allow `bash scripts/release/export_source_zip.sh` for source delivery; workspace zip is forbidden.
