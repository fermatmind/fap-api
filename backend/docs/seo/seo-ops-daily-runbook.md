# Daily SEO Ops Runbook

Task: SEO-OPS-SOP-01B

Type: docs/generated/test only.

This runbook defines the daily SEO Ops checklist. It is an observation and escalation routine only. It does not authorize CMS mutation, Search Channel enqueue or submission, crawler log reads, scheduler activation, collector writes, Digital PR sends, production operations, migrations, UI implementation, fap-web changes, auto-rewrite, auto-link creation, or pSEO generation.

## Daily Checklist

1. Open `/ops/seo`.
2. Review the overview heartbeat for URL Truth, entity, issue, Search Channel, and crawler aggregate freshness signals.
3. Review the safety heartbeat for private-flow leaks, forbidden authority sources, and claim-unsafe public/indexable surfaces.
4. Check URL Truth counts and forbidden-authority counters.
5. Check Issue Queue P0/P1 only for same-day action.
6. Check Search Channel Queue approval and execution states.
7. Confirm live search gates are closed unless an exact human approval is active for a scoped canary.
8. Check crawler aggregate safety counters only. Do not read raw crawler logs.
9. Check Claim Lint `blocked` and `needs_review` counts.
10. Check Internal Link dry-run gaps:
    - missing entity key.
    - legacy_unpaired.
    - unsafe fallback sources.
11. Check Content Publish Rehearsal blockers.
12. Check Digital PR response status for HRZone.
13. Check MBTI Growth Loop status if SEO-GROWTH-MBTI-00 is active.
14. Confirm no uncontrolled scheduler, collector write, Search Channel submission, or search API call occurred.

## Daily Escalation Rules

P0 requires same-day human review and no automatic fix:

- claim-unsafe public/indexable page.
- private-flow leak into public/search surface.
- non-canonical, private, draft, noindex, or claim-unsafe URL in Search Channel.
- scheduler unexpectedly enabled.
- Metabase public exposure.
- raw crawler log persistence.
- frontend fallback becoming canonical authority.
- business DB data leaking into seo_intel.
- Search Channel live gate left open after canary.

P1 requires same-week owner review unless it blocks a core asset:

- core test/research/career page metadata break.
- missing canonical on a core page.
- locale pair missing on core MBTI/research/test asset.
- sitemap/runtime mismatch on core public asset.
- approved Search Channel item stuck without execution result.

P2 goes to repair backlog:

- content publish rehearsal blocker on a draft.
- Internal Link missing entity key or legacy_unpaired gap.
- Claim Lint needs_review on non-public or draft copy.
- crawler aggregate anomaly without public leak.
- missing social metadata or lastmod on non-core article.

P3 remains observation-only unless it trends:

- long-tail query fluctuation.
- dormant indexable URL.
- minor metadata drift.
- non-public wording drift.
- Digital PR no response.

## Daily Forbidden Actions

- Do not change CMS content from the observation dashboard.
- Do not submit URLs from the daily checklist.
- Do not send Digital PR follow-up without exact approval.
- Do not read raw crawler logs.
- Do not treat crawler, search, referral, backlink, or mention data as truth.
- Do not expose Metabase.
- Do not enable scheduler or collector writes.
- Do not auto-fix claims.
- Do not auto-create internal links.
- Do not create pSEO.

## Daily Output

The daily operator note should record:

- date and operator.
- `/ops/seo` safety status.
- P0/P1 issue count and owner.
- Search Channel queue status.
- crawler aggregate warning count.
- Claim Lint blocked/needs_review count.
- Internal Link gap count.
- Content rehearsal blocker count.
- Digital PR HRZone response status.
- MBTI Growth Loop status if active.
- approval gates confirmed closed.

Next task after this PR: `SEO-OPS-SOP-01C`.
