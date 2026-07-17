# Big Five Authority V2 ZH6 promotion readiness (PR49)

> Retired audit artifact. Big Five and Enneagram `PersonalityPublicContentAsset` pages are now permanently text-only. The PR49 media inventory, package, and SHA files are retained only as historical evidence; the readiness command/service has been removed and these artifacts cannot authorize media configuration or a runtime write.

PR49 binds the exact PR48 zh-CN Hub plus five-domain snapshot to repository and read-only production evidence before any new working revision is created. It is a fail-closed readiness package, not a writer or publisher.

## Accepted authority

- GitHub OWNER `fermatmind` supplied the exact PR48 three-SHA approval in PR3139 comment `4990228962` at `2026-07-16T09:24:18Z`; source, PR number, comment id, author login, OWNER association, timestamp and phrase hash are all compared with the immutable authority record. The package does not treat the earlier checked-in confirmation timestamp as independent human evidence.
- The narrow `solo_operator` record binds `admin_user:1` as both author and reviewer for exactly six immutable snapshots. `explicit_self_review=true`; global role separation remains unchanged.
- Operational reviewer approval follows the runtime `admin.totp.enabled` policy. The read-only production observation records `totp_policy_enabled=false` and `totp_enrolled=false`, so the current reviewer permission is approved without inventing an enrollment requirement that production has explicitly disabled. If the policy is enabled, missing enrollment fails closed; any package-versus-runtime policy drift also aborts live preflight. PR49 performs no administrator, configuration or MFA write.
- All six assets retain exactly three visible sources and the PR48 source-permission boundary: public links, brief factual description and original paraphrase only.
- A read-only production observation binds six primary ids, current working/published pointers, public-runtime fingerprints, deployed SHA and rollback targets. Each rollback row is reconstructed from the exact runtime baseline, and the complete read-only/zero-mutation action record is mandatory. Missing or drifted targets or audit evidence must abort later work.

## Historical media HOLD (superseded)

The original production observation found 49 Media Library assets, including 23 published/public/synced/CDN-verified assets and 22 with both hero and OG variants. None was authority-complete for the former `big5:model_hub:zh-CN:hero-og` requirement.

Four Big Five-named Article assets have hero/OG variants, but all lack the required locale, rights, license, provenance, operator approval and Hub content identity. PR49 does not repurpose them. The checked-in package therefore reports:

- eligible Hub media candidates: 0;
- selected Hub media assets: 0;
- `ready_for_working_revision=false`;
- `ready_for_promotion=false`;
- blocker: `unique_hub_hero_og_media_missing`.

These values explain why PR49 originally held. The media uniqueness gate no longer exists, cannot be cleared by adding a Media Library asset, and cannot authorize a new package, working revision, promotion, publication, or controlled write.

## Locked readiness hashes

| Lock | SHA-256 |
| --- | --- |
| release snapshot | `f6eeb698b12111244c335e81425e2d2e83cf50af15a5c1a6e52df2155a0d1e76` |
| package payload | `7e22eadb25f8ae9e2dc2765faef75d822054991b53a284e0d8a09fc69d15f134` |
| package file | `66a08888d4cc2e005d590dfa11b87b6c2c1d6e6750eff17e2f68632a438a71a2` |

These hashes describe the historical fail-closed observation with zero eligible Hub media and a disabled production TOTP policy. They are not an authorization token. The immutable PR48 snapshot, exact confirmation and GitHub OWNER authority remain code-locked as audit evidence. The observation and package must not be refreshed or recomputed for media readiness because the corresponding command, service, CMS fields, and runtime media contract are retired.

## Historical validation (retired)

The checked-in builder, validator, observations, and hashes document the original PR49 decision. They are intentionally not part of the active CMS or release workflow.

## Repository rule impact

CMS/backend remains authoritative. The historical package is non-executable, while the active Personality public-content contract rejects media and image-bearing section or SEO fields.
