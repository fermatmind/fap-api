# PR215 Verify

## What changed
- overrides accept script resolves pack base_dir and writes overrides with expires_at
- verify_mbti.sh prints /tmp/pr4_srv.log + laravel.log tail on create-attempt failure
- CiScalesRegistrySeeder upserts 4 public demo scales
- prepare_sqlite.sh syncs slugs and prints counts
- content_packs region_fallbacks adds US => [CN_MAINLAND, GLOBAL]

## Minimal local verify
```bash
cd backend
bash scripts/ci/prepare_sqlite.sh

# full local acceptance
SERVE_PORT=1815 ART_DIR=backend/artifacts/pr215 bash scripts/pr215_accept.sh

# CI chain
bash scripts/ci_verify_mbti.sh
```
