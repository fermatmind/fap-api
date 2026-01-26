# Cache Keys (Hot Redis)

## Key Rule

Format:

`fap:v=<versionTag>:<domain>:<resource>:...`

Where:

- `versionTag` = `config('app.version')` or `APP_VERSION` (fallback to `dev`), plus `config('cache.prefix')`.
- `cache.prefix` provides environment isolation; `APP_VERSION` provides release-based invalidation.

## Keys List (CacheKeys)

- `CacheKeys::packsIndex()`
  - `fap:v=<versionTag>:content_packs:index`
- `CacheKeys::packManifest($packId, $dirVersion)`
  - `fap:v=<versionTag>:content_packs:manifest:<packId>:<dirVersion>`
- `CacheKeys::packQuestions($packId, $dirVersion)`
  - `fap:v=<versionTag>:content_packs:questions:<packId>:<dirVersion>`
- `CacheKeys::mbtiQuestions($packId, $dirVersion)`
  - `fap:v=<versionTag>:mbti:questions:<packId>:<dirVersion>`
- `CacheKeys::contentAsset($packPath, $relPath)`
  - `fap:v=<versionTag>:asset:<packPath>:<relPath>`

Notes:

- `packPath` / `relPath` keep `/` for readability.
- No spaces in keys; inputs are trimmed.

## Precise Invalidation

- On release, bump `APP_VERSION` (or inject via CI).
- This changes `versionTag` and shifts to a new key space.
- No `flushdb` required in production.

## Local Troubleshooting

- If Redis is unavailable, the app falls back to the default cache store or direct file reads.
- Check logs for hot cache hits/misses:
  - `[HOTCACHE] kind=asset key=... hit=1`
  - `[HOTCACHE] kind=mbti_questions ... hit=1`
- Manual cleanup:
  - Default store: `php artisan cache:clear`
  - Redis (local only): `redis-cli FLUSHDB`
