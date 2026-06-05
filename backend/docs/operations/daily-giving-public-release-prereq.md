# DailyGiving Public Release Prerequisites

Date: 2026-06-05

PR train item: `DAILY-GIVING-PUBLIC-RELEASE-PREREQ-01`

Mode: prerequisite contract only. This PR does not create records, upload proof, process proof files, mutate CMS, publish, index DailyGiving, create trust badges, submit search URLs, run social distribution, or deploy.

## Decision

DailyGiving is not ready for public trust amplification until the public release prerequisites are met and verified by smoke checks. The page must remain `noindex`, and DailyGiving must stay out of sitemap and llms surfaces while first real records are created and reviewed.

## Required Release Gates

- Public records API returns at least one public record.
- Public months API returns at least one month.
- At least one public record is completed or verified.
- At least one public record is verified before any trust badge or high-amplification claim is considered.
- Public records remain `is_indexable=false` during first-record review.
- Each public record has a reviewed proof state: redacted public proof URL, or withheld proof with admin-only reviewer reason.
- Public API never returns raw proof paths, private receipt references, redaction notes, internal notes, or admin user ids.
- DailyGiving pages remain `noindex`.
- DailyGiving routes remain absent from sitemap, `llms.txt`, and `llms-full.txt`.
- Claim lint remains clean for official-partner, endorsement, certification, guaranteed-impact, and unsupported stable-operation claims.

## Trust Boundary

The release gate allows only controlled public record visibility after review. It does not allow a DailyGiving trust badge, public amplification, paid-page trust claim, search submission, social distribution, or official endorsement implication.

## Deferred Until First Record Authorization

The first real record requires a separate private-ledger authorization. Raw receipt storage, redacted proof creation, record review, and public record activation are intentionally outside this PR.
