# PR39 Verify

- [ ] `cd backend && php artisan test --filter ContentLoaderServiceMtimeTest`
  - 期望关键字：`PASS  Tests\\Unit\\Services\\ContentLoaderServiceMtimeTest`
- [ ] `bash backend/scripts/pr39_accept.sh`
  - 期望关键字：`[PR39][PASS] acceptance complete`
  - 期望退出码：`0`
- [ ] `bash backend/scripts/ci_verify_mbti.sh`
  - 期望关键字：`[CI] MVP check PASS`

## 补充校验

- [ ] `bash -n backend/scripts/pr39_accept.sh`
- [ ] `bash -n backend/scripts/pr39_verify.sh`
- [ ] `bash backend/scripts/sanitize_artifacts.sh 39`
