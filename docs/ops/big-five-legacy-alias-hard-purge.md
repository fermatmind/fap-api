# Big Five legacy alias hard-purge runbook

This runbook controls physical removal of the ten English and ten zh-CN Big Five legacy alias CMS assets. The aliases remain valid only as the twenty deterministic single-hop redirects declared by `BigFiveCanonicalRouteCatalog`; no database row is redirect authority.

## Safety boundary

- The command is read-only unless `--execute` and the exact confirmation phrase are supplied.
- The only permitted target is the locked 20-row `org_id=0`, `framework=big_five`, `entity_type=polarity` cohort.
- English aliases must be one complete active cohort or one complete archived cohort. Mixed lifecycle state fails closed.
- zh-CN aliases must be the complete `content_ready`, public, `noindex,follow`, non-sitemap/non-LLMS cohort.
- Both locales must retain exactly 52 canonical assets before and after deletion.
- Any alias-related review attestation target evidence blocks deletion.
- The 104 canonical rows, their revisions/reviews, and every non-target Personality row must retain identical fingerprints.
- Deployment, backup creation, purge execution, cache-only retry, and backup restoration are separate production authorities.

## Read-only preflight

```bash
php artisan personality:big-five-legacy-aliases-purge --json
```

`READY_TO_PURGE` means the exact 20-row cohort is present and the response contains the live per-table row counts and checksums needed for the backup manifest. `PASS_ALREADY_PURGED` means there are zero alias assets and exactly 52 canonical assets per locale. `BLOCKED` is fail closed.

## Backup manifest contract

The verified JSON manifest is hashed as raw bytes and has this shape:

```json
{
  "schema_version": "big-five-legacy-alias-backup.v1",
  "operator_admin_user_id": 1,
  "created_at": "YYYY-MM-DDTHH:MM:SSZ",
  "backup_artifact_sha256": "<64 lowercase hex>",
  "tables": {
    "personality_public_content_assets": {
      "row_count": 20,
      "checksum_sha256": "<preflight checksum>"
    },
    "personality_public_content_asset_revisions": {
      "row_count": "<preflight count>",
      "checksum_sha256": "<preflight checksum>"
    },
    "personality_public_content_asset_revision_reviews": {
      "row_count": "<preflight count>",
      "checksum_sha256": "<preflight checksum>"
    }
  }
}
```

The manifest must describe the same locked live rows at execute time. A valid manifest-file SHA with stale counts or checksums is rejected.

## Separately authorized execute

Only after backend deployment, frontend redirect verification, preflight, and independently verified production backup:

```bash
php artisan personality:big-five-legacy-aliases-purge \
  --execute \
  --confirm=PURGE_BIG_FIVE_LEGACY_ALIAS_ROWS \
  --operator-admin-user-id=1 \
  --backup-manifest=<VERIFIED_BACKUP_MANIFEST> \
  --backup-sha256=<VERIFIED_BACKUP_SHA256> \
  --json
```

Success is `PASS_PURGED`, `deleted_asset_count=20`, zero remaining alias/revision/review/attestation counts, 52 canonical rows per locale, zero canonical/non-target drift, and `cache_closeout_status=PASS_CACHE_CLOSEOUT`. Transaction failure rolls back.

The cache closeout runs only after the deletion transaction commits. It invalidates both locale collection families, all twenty alias detail identities, sitemap-source fresh/stale, sitemap XML/ETag, and the exact frontend `/llms.txt` and `/llms-full.txt` paths through the configured HMAC revalidation endpoint. A cache failure never attempts to restore committed database rows: the command exits non-zero with `PARTIAL_CACHE_CLOSEOUT` and category-level evidence.

After separate authorization, retry only the locked cache set without database writes:

```bash
php artisan personality:big-five-legacy-aliases-purge \
  --cache-closeout-only \
  --confirm=CLOSEOUT_BIG_FIVE_LEGACY_ALIAS_CACHES \
  --operator-admin-user-id=1 \
  --json
```

This mode requires the alias database count to be zero and both canonical cohorts to remain exactly 52. It is idempotent, does not accept `--execute`, and does not touch canonical rows, Media Library, articles, Search Channel, or unrelated Personality cache families.

## Production closeout

After `PASS_CACHE_CLOSEOUT` or `PASS_CACHE_CLOSEOUT_ONLY`, verify:

- database Big Five assets: 104 total, 52 `zh-CN`, 52 `en`;
- legacy alias assets, revisions, revision reviews, and attestation targets: zero;
- public API: 52 assets per locale;
- sitemap and LLMS surfaces: 104 canonical paths and no aliases;
- all twenty legacy paths: exact one-hop 301 to the catalog target, followed by 200;
- Media Library and search submission writes: zero.

Repository rule impact: legacy Big Five aliases are URL-only redirect identities. CMS/database alias authority and the English archive-retirement workflow are permanently removed. Post-purge cache invalidation has a bounded, auditable, independently retryable closeout and never rolls back committed database deletion.
