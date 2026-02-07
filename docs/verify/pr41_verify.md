# PR41 Verify

- [ ] `php artisan test --filter PaymentWebhookLockBusyTest` -> output contains `PASS`
- [ ] `php artisan test --filter PaymentWebhookIdempotencyTest` -> output contains `PASS`
- [ ] `php artisan test --filter CommerceWebhookIdempotencyTest` -> output contains `PASS`
- [ ] `bash backend/scripts/pr41_accept.sh` -> exit code `0`
- [ ] `bash backend/scripts/ci_verify_mbti.sh` -> output contains `[CI] MVP check PASS`

## Notes
- Run commands from repository root unless command explicitly switches to `backend/`.
- Artifacts are generated under `backend/artifacts/pr41/`.
