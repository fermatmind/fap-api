# PR51 Verify

- Branch: `chore/pr51-fix-payment-idempotency-key-scop`
- Title: `Fix payment idempotency key scoping`
- Serve Port: `1851`

## Commands

```bash
php artisan route:list
php artisan migrate
bash backend/scripts/pr51_accept.sh
bash backend/scripts/ci_verify_mbti.sh
```

## Results

- [x] `bash backend/scripts/pr51_accept.sh`
- [x] `bash backend/scripts/ci_verify_mbti.sh`

## Key Checks

- [x] `orders` idempotency unique scope is `org_id + provider + idempotency_key`
- [x] `POST /api/v0.3/orders/{provider}` supports provider-scoped idempotency
- [x] Dynamic question-driven answers submit flow passes
- [x] `config/scales_registry/pack` consistency verified
- [x] Artifacts sanitized

## Artifact Snapshot

- `backend/artifacts/pr51/summary.txt`
- `backend/artifacts/pr51/idempotency_scope_check.txt`
- `backend/artifacts/pr51/pack_seed_config.txt`
