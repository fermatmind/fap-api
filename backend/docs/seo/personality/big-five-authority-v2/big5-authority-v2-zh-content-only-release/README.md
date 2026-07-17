# Big Five Authority V2 — zh-CN content-only release

This release is an operator-scoped exception for the exact 112 `zh-CN` Big Five V2 content assets already present in backend authority storage.

## Scope

- 56 Articles
- 52 Personality public assets
- 2 ContentPages
- 1 Topic
- 1 test LandingSurface

ZH6 uses the approved Task 48 final snapshot. The Topic uses the Task 46 safe snapshot. The remaining assets use the PR37 V2 source payloads, with full family-specific content projection and public-copy cleanup.

For this exact release, author, reviewer, source completeness, editorial dates, revision fingerprints, preview approval and rollback planning are non-blocking by explicit operator direction. This does not change the default publishing rules for other content.

The 52 Personality rows are permanently text-only and carry no media field or deferred marker. The remaining 60 CMS rows retain `media_deferred_by_operator`. The release writes no Media Library row, no image URL and no frontend fallback. English content is out of scope.

## Command

Read-only preflight:

```bash
cd backend
php artisan personality:big-five-authority-v2-zh-content-publish --json
```

Production publish:

```bash
cd backend
php artisan personality:big-five-authority-v2-zh-content-publish --execute --json
```

The execute path is an idempotent database transaction over the exact 112 identities. It publishes the five backend authority surfaces, then verifies surface counts, public/indexable state, 35 ZH6 FAQ rows, media deferral markers and zero English writes before commit.

## Repository rule impact

Backend remains the sole runtime content authority. This exception adds no frontend editorial fallback and does not relax any non-Big-Five or non-Chinese release workflow.
