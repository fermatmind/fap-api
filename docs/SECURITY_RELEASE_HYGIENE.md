# SECURITY RELEASE HYGIENE

1. 对外交付源码包只允许执行：`bash scripts/release/export_source_zip.sh`
2. 对外交付前必须通过：`bash scripts/release/verify_source_zip_clean.sh dist/fap-api-source.zip`
3. 严禁直接对工作区执行 `zip -r` 并分发
