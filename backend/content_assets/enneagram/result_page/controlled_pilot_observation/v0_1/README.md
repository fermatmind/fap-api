# Enneagram Controlled Pilot Observation v0.1

Status: `OBSERVATION_PLAN_ONLY`

This directory defines the post-activation observation plan for the Enneagram result page. It is not an activation artifact and does not authorize production rollout.

The plan starts only after the manual production gate and post-activation smoke have passed for the exact inactive release:

- `enneagram_1r_a_to_1r_h_phase8b_candidate_20260427_a9fd3eb4`

## Observation Windows

- D1
- D7
- D14
- D28

## Metrics

- share clicks
- retest starts and completions
- Big Five cross-test clicks
- MBTI cross-test clicks
- PDF and share errors
- claim gate hits

## Boundaries

- No production activation in this plan.
- No production rollback in this plan.
- No runtime switch in this plan.
- No CMS writes.
- No SEO expansion.
- No sitemap, IndexNow, or search submission.
- No public exposure of attempt ids, raw scores, score vectors, private report payloads, internal hashes, source traces, or selector metadata.

Operational expansion remains blocked until the D1, D7, D14, and D28 evidence is reviewed.
