# PR53 Verify

- Date: 2026-02-08
- Commands:
  - `php artisan route:list`
  - `php artisan migrate`
  - `php artisan test --filter ContentPacksIndexManifestConsistencyTest`
  - `php artisan test --filter ContentPackResolverCacheTest`
  - `bash backend/scripts/pr53_accept.sh`
  - `bash backend/scripts/ci_verify_mbti.sh`
- Result:
  - `php artisan route:list` 通过（目标路由可见）
  - `php artisan migrate` 通过（无待执行迁移）
  - `php artisan test --filter ContentPacksIndexManifestConsistencyTest` 通过
  - `php artisan test --filter ContentPackResolverCacheTest` 通过
  - `bash backend/scripts/pr53_accept.sh` 通过
  - `bash backend/scripts/ci_verify_mbti.sh` 通过
- Artifacts:
  - `backend/artifacts/pr53/summary.txt`
  - `backend/artifacts/pr53/verify.log`
  - `backend/artifacts/pr53/pack_seed_config.txt`
  - `backend/artifacts/pr53/phpunit_content_packs_index_manifest_consistency.txt`
  - `backend/artifacts/pr53/phpunit_content_pack_resolver_cache.txt`
  - `backend/artifacts/verify_mbti/summary.txt`

Step Verification Commands

1. Step 1 (routes): `cd backend && php artisan route:list | grep -E "api/v0.2/content-packs|api/v0.3/scales/\\{scale_code\\}/questions"`
2. Step 2 (migrations): `cd backend && php artisan migrate --force && rm -f /tmp/pr53.sqlite && touch /tmp/pr53.sqlite && DB_CONNECTION=sqlite DB_DATABASE=/tmp/pr53.sqlite php artisan migrate:fresh --force`
3. Step 3 (middleware): `php -l backend/app/Http/Middleware/FmTokenAuth.php && grep -n "DB::table('fm_tokens')" backend/app/Http/Middleware/FmTokenAuth.php && grep -n "attributes->set('fm_user_id'" backend/app/Http/Middleware/FmTokenAuth.php`
4. Step 4 (controller/service/tests): `php -l backend/app/Services/Content/ContentPacksIndex.php && cd backend && php artisan test --filter ContentPacksIndexManifestConsistencyTest && php artisan test --filter ContentPackResolverCacheTest`
5. Step 5 (scripts/CI): `bash -n backend/scripts/pr53_verify.sh && bash -n backend/scripts/pr53_accept.sh && bash backend/scripts/pr53_accept.sh && bash backend/scripts/ci_verify_mbti.sh`
