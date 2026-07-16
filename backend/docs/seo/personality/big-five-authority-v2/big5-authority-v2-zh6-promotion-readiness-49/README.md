# Big Five Authority V2 ZH6 promotion readiness (PR49)

PR49 binds the exact PR48 zh-CN Hub plus five-domain snapshot to repository and read-only production evidence before any new working revision is created. It is a fail-closed readiness package, not a writer or publisher.

## Accepted authority

- GitHub OWNER `fermatmind` supplied the exact PR48 three-SHA approval in PR3139 comment `4990228962` at `2026-07-16T09:24:18Z`; source, PR number, comment id, author login, OWNER association, timestamp and phrase hash are all compared with the immutable authority record. The package does not treat the earlier checked-in confirmation timestamp as independent human evidence.
- The narrow `solo_operator` record binds `admin_user:1` as both author and reviewer for exactly six immutable snapshots. `explicit_self_review=true`; global role separation remains unchanged.
- Operational reviewer approval follows the runtime `admin.totp.enabled` policy. The read-only production observation records `totp_policy_enabled=false` and `totp_enrolled=false`, so the current reviewer permission is approved without inventing an enrollment requirement that production has explicitly disabled. If the policy is enabled, missing enrollment fails closed; any package-versus-runtime policy drift also aborts live preflight. PR49 performs no administrator, configuration or MFA write.
- All six assets retain exactly three visible sources and the PR48 source-permission boundary: public links, brief factual description and original paraphrase only.
- A read-only production observation binds six primary ids, current working/published pointers, public-runtime fingerprints, deployed SHA and rollback targets. Each rollback row is reconstructed from the exact runtime baseline, and the complete read-only/zero-mutation action record is mandatory. Missing or drifted targets or audit evidence must abort later work.

## Media HOLD

The production observation found 49 Media Library assets, including 23 published/public/synced/CDN-verified assets and 22 with both hero and OG variants. None is authority-complete for `big5:model_hub:zh-CN:hero-og`.

Four Big Five-named Article assets have hero/OG variants, but all lack the required locale, rights, license, provenance, operator approval and Hub content identity. PR49 does not repurpose them. The checked-in package therefore reports:

- eligible Hub media candidates: 0;
- selected Hub media assets: 0;
- `ready_for_working_revision=false`;
- `ready_for_promotion=false`;
- blocker: `unique_hub_hero_og_media_missing`.

Exactly one authority-complete Hub asset would clear the media uniqueness gate, but all rights, license, provenance and operator-approval values must be non-empty strings and the media permission must reference the exact locked media-authority SHA. When the runtime TOTP policy is enabled, working-revision readiness additionally requires reviewer enrollment; when disabled, reviewer access follows the same explicit bypass as the Ops middleware. Zero or multiple media candidates, malformed authority fields, a drifted media permission reference, policy drift, or missing enrollment under an enabled policy remain HOLD. Even a fully ready state never authorizes promotion, publication or a controlled write.

## Locked readiness hashes

| Lock | SHA-256 |
| --- | --- |
| release snapshot | `f6eeb698b12111244c335e81425e2d2e83cf50af15a5c1a6e52df2155a0d1e76` |
| package payload | `7e22eadb25f8ae9e2dc2765faef75d822054991b53a284e0d8a09fc69d15f134` |
| package file | `66a08888d4cc2e005d590dfa11b87b6c2c1d6e6750eff17e2f68632a438a71a2` |

These hashes describe the current fail-closed observation with zero eligible Hub media and a disabled production TOTP policy. They are not an authorization token. The immutable PR48 snapshot, exact confirmation and GitHub OWNER authority remain code-locked; review assets and source-permission rows are reconstructed from that locked snapshot, rollback rows are reconstructed from the observation-bound runtime baseline, reviewer permission is bound to both observed TOTP policy and enrollment state, the selected media is exact-bound to the sole observed candidate, and the exact read-only/zero-mutation action record is required. None can be replaced or omitted by recomputing downstream hashes. The production observation may be refreshed after separately controlled Media Library, reviewer-enrollment or TOTP-policy work; its SHA is then bound into the package media authority and release-lock material, while the rebuilt package file is verified by its reviewed `.sha256` sidecar and by a live read-only database preflight. A refreshed observation must be named `production-observation.json` and be a sibling of the output package; the builder rejects any non-sibling override before writing. No service-constant change is required for a legitimate fresh observation.

## Validation

```bash
node generated/big-five-authority-v2/big5-authority-v2-zh6-promotion-readiness-49/build-package.mjs
node generated/big-five-authority-v2/big5-authority-v2-zh6-promotion-readiness-49/validate-package.mjs
cd backend
php artisan personality:big-five-authority-v2-zh6-promotion-readiness \
  --package=../generated/big-five-authority-v2/big5-authority-v2-zh6-promotion-readiness-49/promotion-readiness-package.json \
  --package-only --json
php artisan personality:big-five-authority-v2-zh6-promotion-readiness \
  --package=../generated/big-five-authority-v2/big5-authority-v2-zh6-promotion-readiness-49/promotion-readiness-package.json \
  --json
APP_ENV=testing DB_CONNECTION=sqlite DB_DATABASE=':memory:' php artisan test tests/Feature/SEO/BigFiveAuthorityV249Test.php --no-ansi
vendor/bin/pint --test tests/Feature/SEO/BigFiveAuthorityV249Test.php
```

## Repository rule impact

CMS/backend and Media Library ownership are unchanged. This package adds a read-only, non-executable authority gate and a narrow cohort review record; it does not create a new publisher, relax global reviewer separation, change the public projection, or perform a CMS/database/media/indexability/sitemap/LLMS/schema/Search/cache/deploy action.
