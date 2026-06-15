# Validation Plan

## Required Local Checks

- `python3 -m json.tool backend/content_assets/personality_public/big_five_v1_seed.json >/dev/null`
- `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-bigfive-editorial-repair-02.sqlite php artisan migrate --force --no-ansi`
- `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-bigfive-editorial-repair-02.sqlite php artisan personality-public-assets:import --framework=big_five --no-ansi`
- `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-bigfive-editorial-repair-02.sqlite php artisan personality-public-assets:import --framework=big_five --write --no-ansi`
- repeat write import for idempotence
- `APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-bigfive-editorial-repair-02.sqlite ./vendor/bin/phpunit tests/Feature/V0_5/PersonalityPublicContentAssetContractTest.php --filter BigFive --testdox`
- `git diff --check`

## Expected

- 94 valid assets.
- 0 indexable/sitemap/llms eligible assets.
- 34 public API render candidates.
- 60 facet stubs hidden from public list/detail endpoints.
- 0 public wording/private boundary hits.
- 0 duplicate pairs above the 0.72 regression threshold.

## Actual Local Check Results

- JSON parse: PASS.
- SQLite migrate: PASS with /tmp/fap-bigfive-editorial-repair-02.sqlite.
- Import dry-run: PASS, assets_found=94, valid_count=94, errors_count=0, indexable_count=0, sitemap_eligible_count=0, llms_eligible_count=0.
- Import write: PASS, will_create=94, indexable_count=0, sitemap_eligible_count=0, llms_eligible_count=0.
- Import second write idempotence: PASS, will_skip=94.
- Route list for personality-content-assets: PASS, 3 routes present.
- Focused PHPUnit contract test file: PASS, 10 tests / 366 assertions.
- git diff --check: PASS.
- backend/scripts/ci_verify_mbti.sh: PASS.
