# PR38 Verify

## Local
- Run:
  - bash backend/scripts/pr38_accept.sh
  - bash backend/scripts/ci_verify_mbti.sh

## Expected
- pr38_accept.sh exit 0
- ContentLoaderMtimeCacheTest PASS
- AttemptReportOwnershipTest PASS（包含 share_id 场景）
- ci_verify_mbti.sh exit 0 and contains "[CI] MVP check PASS"
