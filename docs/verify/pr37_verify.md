# PR37 Verify

## Local
- Run:
  - bash backend/scripts/pr37_accept.sh
  - bash backend/scripts/ci_verify_mbti.sh

## Expected
- pr37_accept.sh exit 0
- PaymentWebhookStripeSignatureTest PASS
- PaymentWebhookProcessorLockTest PASS
- ci_verify_mbti.sh exit 0 and contains "[CI] MVP check PASS"
