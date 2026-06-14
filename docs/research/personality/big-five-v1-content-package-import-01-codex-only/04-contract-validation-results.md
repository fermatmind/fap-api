# Contract Validation Results

## SQLite Import Validation

Commands used:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-bigfive-import-01.sqlite php artisan migrate:fresh --force
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-bigfive-import-01.sqlite php artisan personality-public-assets:import --framework=big_five
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-bigfive-import-01.sqlite php artisan personality-public-assets:import --framework=big_five --write
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=/tmp/fap-bigfive-import-01.sqlite php artisan personality-public-assets:import --framework=big_five --write
```

Results:

- Dry-run: `assets_found=94`, `valid_count=94`, `errors_count=0`, `will_create=94`.
- First write: `will_create=94`, `will_update=0`, `will_skip=0`.
- Second write: `will_create=0`, `will_update=0`, `will_skip=94`.
- Indexable count: 0.
- Sitemap eligible count: 0.
- llms eligible count: 0.

## PHPUnit

Command:

```bash
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=:memory: php artisan test --filter=PersonalityPublicContentAssetContractTest
```

Result:

- PASS.
- 10 tests.
- 151 assertions.

## Local Environment Note

Running the import command without testing SQLite attempted to use the local MySQL default connection and failed with local root access denied. This was an environment/default connection issue, not a seed validation issue. The scoped validation used testing SQLite.
