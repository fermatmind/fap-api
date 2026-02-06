# PR36 Verify

## Local
- Run:
  - bash backend/scripts/pr36_accept.sh
  - bash backend/scripts/ci_verify_mbti.sh

## Expected
- pr36_accept.sh exit 0
- php artisan test --filter GenericLikertDriverTest PASS
- ci_verify_mbti.sh exit 0 and contains "[CI] MVP check PASS"
