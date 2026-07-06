# MBTI Main FAQ D7 Observation Decision

Date prepared: 2026-07-06

Observation window: 2026-07-12 or later

PR/task: `MBTI-MAIN-FAQ-D7-OBSERVATION-01`

Final verdict: `BLOCKED_BY_DATE_WINDOW`

## Decision

Do not run MBTI FAQ D7 observation on 2026-07-06.

The D7 observation window has not arrived. Running the readback now would mix D0/D1 noise with a D7 label and would violate the train rule that observation windows must be respected.

## Required Window

Earliest valid run date: 2026-07-12

## Train Action

Record the date-window block, append sidecar issue, merge this generated-only blocked report if checks pass, and continue to D28 observation handling.

## Explicit Non-Actions

This PR does not:

- query GSC
- query GA
- read production analytics credentials
- submit URLs
- update Search Channel
- update CMS
- modify FAQ, JSON-LD, metadata, sitemap, `llms`, canonical, or noindex
- verify deployment
- trigger deployment
