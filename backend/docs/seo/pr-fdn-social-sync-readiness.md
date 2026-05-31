# PR-FDN-SOCIAL-SYNC-READINESS Report

## Executive Summary

`PR-FDN-SOCIAL-SYNC-READINESS` is a read-only readiness review for Foundation Daily Giving social sync.

The current backend already supports manual social-link recording for published Daily Giving records:

- Social URL fields exist on `daily_giving_records`.
- The Daily Giving model accepts and casts those fields.
- Filament Ops exposes manual entry fields for X, LinkedIn, Weibo, Xiaohongshu, and other social links.
- The public Foundation Daily Giving API returns the social-link fields for public records.

The current backend does not include automatic social posting, social platform credentials, platform API clients, a posting queue, or an approval workflow for automated distribution. The safe next step is a manual social sync MVP: humans publish platform posts externally, then record the resulting URLs in the existing Daily Giving social-link fields.

## Current Capability

Evidence found in code:

- `backend/database/migrations/2026_05_30_000100_create_daily_giving_records_table.php` defines:
  - `social_x_url`
  - `social_linkedin_url`
  - `social_weibo_url`
  - `social_xiaohongshu_url`
  - `social_other_links`
- `backend/app/Models/DailyGivingRecord.php` marks these fields fillable and exposes them through `toPublicArray()`.
- `backend/app/Filament/Ops/Resources/DailyGivingRecordResource.php` provides manual Ops form fields for those links.
- `backend/app/Http/Resources/Foundation/DailyGivingRecordResource.php` returns the model public payload.
- `backend/app/Http/Controllers/API/V0_5/Foundation/DailyGivingRecordController.php` serves published public records through the Foundation API.
- `backend/tests/Feature/Foundation/DailyGivingRecordPublicApiTest.php` verifies social URLs are exposed for a public record.
- `backend/tests/Feature/Foundation/DailyGivingRecordPublicationGateTest.php` verifies the public payload includes the social-link keys.

## Gaps

Automatic social sync is not ready because the repository does not currently provide:

- Social platform credential storage or runtime config for this workflow.
- X, LinkedIn, Weibo, Xiaohongshu, or other posting API clients for Daily Giving.
- A durable post draft model.
- A human approval gate for platform-specific copy.
- A posting queue, retry policy, rate-limit handling, or external response audit trail.
- A safety boundary for platform terms, account ownership, image attachments, or failure recovery.

## Recommended Strategy

Use the existing manual-link model first:

1. Human prepares platform copy from an approved Daily Giving record.
2. Human publishes the post directly in each social platform.
3. Human records the resulting social URLs in the existing Ops fields.
4. Public API and frontend surfaces render those social links from backend authority.

This keeps production safe because it avoids credential handling, automated external API calls, and unreviewed social posts.

## Proposed Future PR Boundary

Recommended next PR:

`PR-FDN-SOCIAL-SYNC-MVP-01`

Suggested scope:

- Add a backend-authoritative manual social sync checklist/status for Daily Giving records.
- Keep platform posting manual.
- Store only published post URLs and review metadata.
- Do not add credentials, external platform API calls, automatic posting, or Search Channel actions.

Any future automatic posting should be a separate, explicitly approved track after credential, approval, audit, rate-limit, and platform-policy boundaries are designed.

## What Was Not Done

- No production data was read or mutated.
- No CMS mutation was performed.
- No deploy was performed.
- No Search Channel action was performed.
- No URL submission was performed.
- No external search or social API was called.
- No social platform credentials were handled.
- No automatic posting was added.

## Final Decision

`pr_fdn_social_sync_readiness_completed_ready_for_manual_social_sync_mvp`

## Next Task

`PR-FDN-SOCIAL-SYNC-MVP-01`
