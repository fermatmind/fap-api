# Big Five Authority V2 ZH6 promotion readiness (PR49)

PR49 binds the exact PR48 zh-CN Hub plus five-domain snapshot to repository and read-only production evidence before any new working revision is created. It is a fail-closed readiness package, not a writer or publisher.

## Accepted authority

- GitHub OWNER `fermatmind` supplied the exact PR48 three-SHA approval in PR3139 comment `4990228962` at `2026-07-16T09:24:18Z`; the package does not treat the earlier checked-in confirmation timestamp as independent human evidence.
- The narrow `solo_operator` record binds `admin_user:1` as both author and reviewer for exactly six immutable snapshots. `explicit_self_review=true`; global role separation remains unchanged.
- All six assets retain exactly three visible sources and the PR48 source-permission boundary: public links, brief factual description and original paraphrase only.
- A read-only production observation binds six primary ids, current working/published pointers, public-runtime fingerprints, deployed SHA and rollback targets. Missing or drifted targets must abort later work.

## Media HOLD

The production observation found 49 Media Library assets, including 23 published/public/synced/CDN-verified assets and 22 with both hero and OG variants. None is authority-complete for `big5:model_hub:zh-CN:hero-og`.

Four Big Five-named Article assets have hero/OG variants, but all lack the required locale, rights, license, provenance, operator approval and Hub content identity. PR49 does not repurpose them. The checked-in package therefore reports:

- eligible Hub media candidates: 0;
- selected Hub media assets: 0;
- `ready_for_working_revision=false`;
- `ready_for_promotion=false`;
- blocker: `unique_hub_hero_og_media_missing`.

Exactly one authority-complete Hub asset would clear the media uniqueness gate for later working-revision preparation. Zero or multiple candidates remain HOLD. Even the unique-candidate state never authorizes promotion, publication or a controlled write.

## Locked readiness hashes

| Lock | SHA-256 |
| --- | --- |
| release snapshot | `cc562c87387fa337786f49bd5d1efb5d0c1d1381dd65e127c913bf259b300973` |
| package payload | `46f25a5b30b770a61b57bbdb330076061ae847e23012f290dd1a6011a2beda28` |
| package file | `b85e7041c2292751e79d463fa292c863cc56b2c7a726d2568e65771ca1f4283c` |

These hashes describe the current fail-closed observation with zero eligible Hub media. They are not an authorization token and must be rebuilt from a fresh read-only observation after a separately authorized Media Library intake supplies exactly one eligible asset.

## Validation

```bash
node generated/big-five-authority-v2/big5-authority-v2-zh6-promotion-readiness-49/build-package.mjs
node generated/big-five-authority-v2/big5-authority-v2-zh6-promotion-readiness-49/validate-package.mjs
cd backend
php artisan personality:big-five-authority-v2-zh6-promotion-readiness \
  --package=../generated/big-five-authority-v2/big5-authority-v2-zh6-promotion-readiness-49/promotion-readiness-package.json \
  --package-only --json
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test tests/Feature/SEO/BigFiveAuthorityV249Test.php --no-ansi
vendor/bin/pint --test tests/Feature/SEO/BigFiveAuthorityV249Test.php
```

## Repository rule impact

CMS/backend and Media Library ownership are unchanged. This package adds a read-only, non-executable authority gate and a narrow cohort review record; it does not create a new publisher, relax global reviewer separation, change the public projection, or perform a CMS/database/media/indexability/sitemap/LLMS/schema/Search/cache/deploy action.
