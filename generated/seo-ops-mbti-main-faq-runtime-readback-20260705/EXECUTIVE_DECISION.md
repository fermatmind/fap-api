# MBTI Main FAQ Production Runtime Readback

Decision: BLOCKED_FAQ_NOT_PROPAGATED

Observed at: 2026-07-05T08:59:39Z

The production backend API already returns 8 FAQ entries for the zh MBTI main test landing content, but the production public page still renders 4 visible FAQ entries and 4 FAQPage JSON-LD entries.

This does not satisfy the condition for `MBTI-MAIN-FAQ-SCHEMA-PARITY-REPAIR-01`, because visible FAQ and JSON-LD are currently in parity with each other at 4. The mismatch is API 8 vs public page/schema 4, so the next action should not be schema renderer repair unless a later readback shows visible FAQ = 8 with JSON-LD mismatch.

No CMS write, production import, deployment, sitemap, llms, Search Channel, or runtime code change was performed.
