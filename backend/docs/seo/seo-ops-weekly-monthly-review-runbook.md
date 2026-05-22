# Weekly and Monthly SEO Ops Review Runbook

Task: SEO-OPS-SOP-01C

Type: docs/generated/test only.

This runbook defines the weekly and monthly SEO Ops review cadence. It is a review and decision-support contract only. It does not authorize production operations, runtime services, migrations, CMS mutation, scheduler activation, Search Channel writes, crawler log reads, fap-web changes, Digital PR sends, claim auto-rewrite, internal link auto-creation, or pSEO generation.

## Weekly Review

Review weekly:

- Search Channel Queue backlog.
- Search Channel approval candidates.
- crawler aggregate trend, with no raw logs.
- content publish rehearsal blockers.
- internal link graph coverage.
- Chinese claim lint backlog.
- Research URL observation.
- Digital PR response, referral, and mention tracking.
- MBTI cluster URL Truth and issue trend.
- approved search performance feedback if available.
- repair backlog decisions.

Weekly escalation:

- P0 remains same-day even during weekly review.
- P1 items get owner and target week.
- P2 items move into repair backlog.
- P3 items remain observation-only unless repeated or growing.

Weekly outputs:

- Search Channel backlog summary.
- crawler aggregate observation summary.
- content rehearsal blocker summary.
- internal link graph coverage summary.
- claim lint backlog summary.
- Research URL observation summary.
- Digital PR HRZone/HREC state.
- MBTI cluster risk and opportunity notes.
- repair backlog decisions and owners.

## Monthly Review

Review monthly:

- entity cluster performance.
- content decay and repair queue.
- internal link graph coverage by entity family.
- claim safety audit.
- Digital PR outcome and next-wave decision.
- Search Channel submission audit.
- crawler aggregate observation review.
- revenue/funnel review where backend truth exists.
- MBTI Growth Loop 7/14/28-day review.
- next entity selection.

Monthly decisions:

- whether to keep MBTI in observation.
- whether to run another MBTI content/internal-link/Search Channel/Digital PR wave.
- whether to promote an entity cluster for repair.
- whether the system is ready to consider Big Five, RIASEC, or Career expansion.
- whether any approval gate needs tightening.

## Observation-only Signals

The following may inform review but must not become truth:

- search performance feedback.
- crawler aggregate behavior.
- referral traffic.
- Digital PR response, mention, or backlink observation.
- frontend runtime observations.
- static sitemap or llms output.

## Human Approval Boundaries

Weekly/monthly review may recommend, but must not execute without exact approval:

- CMS publish or content mutation.
- Search Channel enqueue.
- Search Channel live submission.
- crawler log production canary.
- scheduler activation.
- production migration.
- backend deploy.
- public Metabase exposure.
- Digital PR send or follow-up.
- claim override.
- internal link mutation.
- pSEO generation.

Next task after this PR: `SEO-OPS-SOP-01D`.
