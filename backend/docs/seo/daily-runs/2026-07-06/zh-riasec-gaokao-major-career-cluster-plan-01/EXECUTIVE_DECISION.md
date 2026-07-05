# ZH-RIASEC-GAOKAO-MAJOR-CAREER-CLUSTER-PLAN-01

Date: 2026-07-06
Repo: fap-api
Scope: generated-only / read-only Chinese RIASEC, gaokao, major, and career cluster plan
Runtime impact: none
CMS impact: none
Search submission impact: none
Deploy impact: none

## Decision

Decision output: `zh_riasec_gaokao_major_career_cluster_plan_completed_ready_for_topic_selection`

The Chinese RIASEC / gaokao / major / career cluster is the best next topical growth lane after the current read-only audits. It already has enough public URL inventory to plan a coherent cluster without writing new content in this PR.

The cluster should be structured around one money owner and four support layers:

1. Money owner: `/zh/tests/holland-career-interest-test-riasec`
2. Model explainer: RIASEC / Holland career-interest explanation pages.
3. Gaokao and major scenario pages: score, parent conflict, unwanted adjustment, hot major fit, math/computer/AI fit.
4. Career direction pages: confusion, career-interest vs personality, job/major mismatch, career guide routing.
5. Entity expansion later: RIASEC dimensions, majors, careers, and course checks after authority gates.

## Priority Sequence

| Priority | Work lane | Reason |
| --- | --- | --- |
| P0 | Strengthen current RIASEC test owner and existing RIASEC explanation/scenario links through evidence-only planning first. | Direct career-interest and gaokao/major intent is already clustered around RIASEC. |
| P1 | Build a result-reading and course/major use-case bridge plan. | The result inventory showed RIASEC lacks a dedicated result interpretation page. |
| P2 | Separate gaokao pages by decision situation: score shortlist, adjustment rejection, parent conflict, hot major, math/computer/AI concern. | These are high-intent Chinese scenarios with clear user jobs. |
| P3 | Connect scenario pages back to test owner and career guides without deterministic career claims. | Keeps RIASEC as exploration signal, not precise recommendation. |

## Claim Boundary

The cluster may say RIASEC helps users compare interests, activity preferences, course/major fit questions, and career exploration directions.

The cluster must not say RIASEC:

- predicts admission, employment, salary, or career success;
- selects the best major automatically;
- proves ability, IQ, mental health, or personality quality;
- replaces counselors, teachers, parents, or professional advice;
- is an official provider/certification unless separately evidenced.

## Next Train Item

Proceed to:

`DAILY-MODE-C-TOPIC-SELECTION-20260706`

Reason: the cluster plan confirms RIASEC/gaokao/major/career as a good candidate lane, but actual topic selection should happen in the daily Mode C package scope, not here.

## Deferred Items

This PR intentionally does not:

- create article drafts;
- rewrite existing articles;
- write CMS;
- publish or unpublish content;
- change internal links, CTA, sitemap, `llms`, schema, canonical, noindex, title, meta, or H1;
- access GSC/GA as purchase truth;
- submit URLs or trigger deploy.
