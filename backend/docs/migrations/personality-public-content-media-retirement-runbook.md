# Personality Public Content Media Retirement Evidence

## Scope

This retirement removes `personality_public_content_assets.media_json` after
Big Five and Enneagram public content was made permanently text-only. The same
migration removes runtime SEO image aliases and operator media-deferred markers.
Immutable historical revision snapshots and release evidence remain unchanged.

This repository records the approved schema intent. It does not claim that a
production backup has been captured or that the production migration has run.

## Operator Checklist

Before running this migration outside local or test environments, the operator must:

1. Confirm the deployed API and frontend versions no longer require Personality
   public-content media fields.
2. Capture a database backup covering `personality_public_content_assets`, with
   row count, checksum, operator, and timestamp in the production change record.
3. Verify that no Big Five or Enneagram CMS workflow writes `media_json`, SEO
   image aliases, Markdown images, or HTML `<img>` content.
4. Run the schema migration only after the API deployment is ready, then run
   `php artisan personality-public-assets:retire-media-fields --write --confirm=RETIRE_PERSONALITY_PUBLIC_CONTENT_MEDIA_FIELDS`.
5. Verify the column is absent, the cleanup command reports zero remaining
   rows on a follow-up dry run, and public API payloads omit `media` and
   `media_authority`.
6. Keep the global Media Library unchanged because it remains authoritative for
   Articles, Topics, Landing Surfaces, MBTI, tests, results, and other resources.

## Rollback Strategy

This is a forward-only contract retirement. `down()` does not recreate the
column. Emergency schema recovery requires restoring the verified backup into a
staging table, validating it, and applying a separately reviewed forward
migration. Historical revision snapshots are audit material and must not be
projected as live media during recovery.
