# Executive Decision

Task: `MONEY-INTENT-TDK-DRYRUN-CANDIDATE-SELECTION-01`

Final verdict: `BLOCKED_BY_GSC_DATA_QUALITY`

No TDK dry-run candidates are selected in this PR. The required GSC/seo_intel quality gate is not passable from current evidence, and this card is not authorized to call live GSC, import data, write CMS fields, or mutate title/meta/H1/FAQ/body copy.

The train should continue because this blocker is external to the current generated-only PR scope. The current PR records the blocked state and sidecar issue only.

No CMS write, runtime/API mutation, sitemap or llms mutation, schema mutation, fap-web edit, Search submission, database write, deploy, cache invalidation, production import, or TDK copy generation was performed.
