# IQ V1 Four-Task Dependency Order

## Which Task Should Run First?

1. `IQ-V1-RESULT-PAGE-SAFE-SCORING-QA-FIX-01`

Reason: result-page forbidden output safety must be fixed before smoke or launch. In current main this appears already effective after PR #2714 and #2716, so a follow-up PR is not required unless new tests prove a regression.

## Recommended Order

1. Result-page safe scoring QA fix or confirm-no-op coverage.
2. Positioning lock.
3. Landing-copy CMS dry-run.
4. Item dimension tagging.

Given current main:

1. Positioning and landing dry-run may proceed because result safety is effective and positioning lock exists.
2. Item dimension tagging can run after or parallel to landing dry-run if it remains metadata-only and does not change scoring.

## Parallelizable Tasks

- `IQ-V1-LANDING-COPY-CMS-DRY-RUN-01` and `IQ-V1-ITEM-DIMENSION-TAGGING-IMPLEMENTATION-01` can run in parallel after confirming result safety and positioning lock.
- Do not run a production smoke in this four-task scan.

## Tasks Depending on Result-Page Forbidden Output Being Fixed

- Landing-copy CMS dry-run, because public copy must not promise outputs that result/report safety cannot support.
- Any live smoke or launch validation, though live smoke is out of scope here.

## Tasks Requiring GPT 5.5 Pro Review

- Landing-copy CMS dry-run before CMS write or public copy promotion.
- Any future relaxation of positioning lock or claim boundary.
- Optional for item dimension tagging if owner wants psychometric language review, but not required for metadata-only safe aliases.

## Tasks Requiring Human Approval

- CMS write, CMS draft creation, CMS publish, production deploy, revalidation, search submission, production smoke with real user attempts, norm import, IQ estimate/percentile enablement, PDF/certificate claims, and any relaxation of forbidden claim boundaries.

## Tasks Not Suitable for Automatic PR Train

- Any task that writes CMS or publishes copy.
- Any task that imports norm tables or enables IQ estimate/percentile claims.
- Any task that changes answer keys, correct answers, scoring formulas, item order, assets, or images.
- Any task that triggers production deploy, production smoke, revalidation, cache purge, or search submission.
