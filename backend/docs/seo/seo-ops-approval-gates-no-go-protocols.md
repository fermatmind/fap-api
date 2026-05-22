# SEO Ops Approval Gates and No-go Protocols

Task: SEO-OPS-SOP-01D

Type: docs/generated/test only.

This contract defines human approval gates, no-go rules, P0 triggers, and exact approval boundary templates. It does not implement gates, runtime services, env edits, deployment changes, production actions, fap-web changes, or CMS mutations.

## Human Approval Required

Exact human approval is required for:

- CMS publish.
- CMS content mutation.
- Search Channel enqueue.
- Search Channel live submission.
- GSC, Baidu, IndexNow, Bing, 360, Sogou, or Shenma calls.
- crawler log production canary.
- scheduler activation.
- production migration.
- backend deploy.
- public Metabase exposure.
- Digital PR send or follow-up.
- claim override.
- internal link mutation.
- pSEO generation.
- bulk content generation.
- production env edit.

## No-go Rules

Routine SEO Ops must not:

- submit draft, private, noindex, or claim-unsafe URLs.
- expose Metabase publicly.
- enable scheduler without approval.
- read raw production crawler logs without exact approval.
- treat crawler log as URL Truth.
- treat frontend fallback, static sitemap, static llms, search response, local copy, or Digital PR mention as URL Truth.
- auto-publish.
- auto-fix CMS content.
- auto-rewrite claims.
- auto-create internal links.
- send bulk Digital PR outreach.
- buy backlinks.
- present RIASEC, Big Five, or Career Graph as precise career recommender authority.
- retry Search Channel without gate.

## P0 Triggers

Escalate P0 for:

- claim-unsafe public/indexable page.
- private-flow leak into public/search surface.
- submitted private, noindex, non-canonical, draft, or claim-unsafe URL.
- Metabase public exposure.
- scheduler unexpectedly enabled.
- raw crawler logs persisted.
- frontend fallback becomes canonical authority.
- business DB leak into seo_intel.
- Search Channel live gate left open after canary.

## Exact Approval Phrase Templates

Search Channel live submission:

`I approve this single Search Channel live submission for [channel] queue item [id] now.`

Crawler Log production canary:

`I approve this read-only crawler log production canary for [source] with dry-run and no-write now.`

Backend deploy:

`I approve this backend deploy for release [sha] now.`

Digital PR send:

`I approve sending the [target] canary email now.`

CMS publish:

`I approve publishing CMS item [type]/[id-or-slug] now.`

Production migration:

`I approve running production migration [migration-or-path] now.`

Scheduler activation:

`I approve enabling scheduler [job-name] now.`

## Gate Behavior

- Approval must be exact, scoped, current, and human-provided.
- Approval must name target, channel, or release where applicable.
- Approval must not be inferred from prior consent.
- Approval must not authorize bulk behavior unless explicitly named and allowed by the task.
- Approval does not bypass claim safety, URL Truth, private-flow, canonical, robots, or noindex gates.

Next task after this PR: `SEO-OPS-SOP-01E`.
