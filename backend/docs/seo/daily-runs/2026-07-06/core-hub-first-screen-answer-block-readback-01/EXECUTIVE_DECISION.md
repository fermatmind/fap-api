# Core Hub First-Screen Answer Block Readback Decision

Date: 2026-07-06

PR/task: `CORE-HUB-FIRST-SCREEN-ANSWER-BLOCK-READBACK-01`

Final verdict: `FIRST_SCREEN_READBACK_READY`

## Decision

The 12 six-test hub routes are publicly reachable and expose first-screen candidate text, but first-screen answer-block quality is uneven.

The strongest first-screen surfaces are:

- Chinese MBTI
- English MBTI
- Chinese Big Five
- Chinese Enneagram
- English RIASEC
- Chinese IQ
- Chinese EQ

The routes that remain partial in the bounded readback are:

- English Big Five
- English Enneagram
- Chinese RIASEC
- English IQ
- English EQ

These partial findings should feed the next FAQ parity and visible claim-boundary cards. They do not authorize content, schema, metadata, sitemap, `llms`, CMS, or frontend changes.

## Method

Unauthenticated public HTTP reads were taken from `https://fermatmind.com` on 2026-07-06.

For each route, the readback captured:

- HTTP status
- `<title>`
- meta description
- `<h1>`
- first 1,400 characters of stripped page text as a first-screen proxy
- four answer-block proxy flags:
  - scale definition present
  - free result expectation present
  - visible claim boundary present
  - primary CTA present

This is a text readback proxy, not a visual screenshot assertion.

## Boundary

This PR does not change any public page, CMS record, API response, JSON-LD, sitemap, `llms`, metadata, canonical, robots policy, frontend runtime, production import, or deployment state.
