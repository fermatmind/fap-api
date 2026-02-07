# PR40 Verify

- [ ] `cd backend && php artisan test --filter PaymentWebhookStripeSignatureTest`
  - 期望关键字：`PASS  Tests\\Feature\\Commerce\\PaymentWebhookStripeSignatureTest`
- [ ] `cd backend && php artisan test --filter PaymentWebhookIdempotencyTest`
  - 期望关键字：`PASS  Tests\\Feature\\Commerce\\PaymentWebhookIdempotencyTest`
- [ ] `bash backend/scripts/pr40_accept.sh`
  - 期望关键字：`[PR40][PASS] acceptance complete`
  - 期望退出码：`0`
- [ ] `bash backend/scripts/ci_verify_mbti.sh`
  - 期望关键字：`[CI] MVP check PASS`

## 补充校验

- [ ] `bash -n backend/scripts/pr40_verify.sh`
- [ ] `bash -n backend/scripts/pr40_accept.sh`
- [ ] `bash backend/scripts/sanitize_artifacts.sh 40`
