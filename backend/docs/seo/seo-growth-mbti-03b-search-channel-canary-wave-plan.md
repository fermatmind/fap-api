# MBTI Search Channel Canary Wave Plan

Task: SEO-GROWTH-MBTI-03B

Train: SEO-GROWTH-MBTI-PR-TRAIN-01

This is a docs / generated JSON / tests-only planning contract. It does not enqueue Search Channel Queue rows, does not submit URLs, does not open live gates, does not edit environment configuration, and does not call external search APIs.

## Purpose

The MBTI Search Channel canary wave may be planned only after the MBTI URL Truth and claim lint gates pass. This contract defines candidate URLs, deferred URL families, eligibility preconditions, and the exact approval template required by a future human-approved live canary task.

## Candidate URLs

These URLs are candidates only after URL Truth and claim gates pass:

- `/en/tests/mbti-personality-test-16-personality-types`
- `/zh/tests/mbti-personality-test-16-personality-types`
- `/en/research/mbti-personality-types-salary-turnover-report`
- `/zh/research/mbti-personality-types-salary-turnover-report`

## Deferred URLs

These surfaces remain deferred until backend authority and claim gates pass:

- `/en/topics/mbti`
- `/zh/topics/mbti`
- MBTI personality type pages
- MBTI article pages

## Required Preconditions

Every future canary candidate must satisfy all of the following:

- URL Truth verified by backend-authoritative source.
- Source authority is allowed.
- Canonical URL is confirmed.
- URL is public.
- URL is indexable.
- URL is claim-safe.
- URL is not draft.
- URL is not noindex.
- URL is not private.
- Dry-run completes before enqueue.
- Human approval is recorded before live submit.
- Canary is one item only.
- Bulk submit remains forbidden.
- Live gates are closed after any approved canary.

## Approval Template

Future live canary approval must use an exact, human-authored instruction scoped to one URL:

`I approve SEO-GROWTH-MBTI-03B live Search Channel canary for exactly one URL: {url}. Keep live gates closed after this canary. No bulk submit.`

This template is inert in this PR. It does not grant approval, enqueue a URL, submit a URL, or open a live gate.

## Authority Boundary

Search Channel distributes only approved URL Truth. Search engine responses, crawler logs, frontend fallback, static sitemap, static llms, Digital PR mentions, and local copies are observation only and must not become URL Truth.

## Next Task

SEO-GROWTH-MBTI-04
