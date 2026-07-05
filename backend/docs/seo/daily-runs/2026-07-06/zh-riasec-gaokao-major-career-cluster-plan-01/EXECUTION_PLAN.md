# Execution Plan

This is a planning artifact only. It does not authorize CMS writes or article creation.

## Phase 1: Read-Only Topic Selection

Use `DAILY-MODE-C-TOPIC-SELECTION-20260706` to select one topic package from this cluster.

Recommended candidate order:

1. `gaokao-major-adjustment-unacceptable-major-checklist`
2. `gaokao-score-major-shortlist-riasec-checklist`
3. `hot-major-fit-riasec-course-career-checklist`
4. `math-not-good-computer-ai-major-course-riasec-checklist`
5. `unwanted-major-adjustment-riasec-transfer-plan`

Selection criteria:

- high user urgency;
- clean claim boundary;
- direct bridge to RIASEC test owner;
- no need for GSC quality gate;
- no production import or runtime change.

## Phase 2: Distribution Asset Package

Use `DAILY-DISTRIBUTION-ASSET-PACKAGE-20260706` to create generated-only distribution assets for the selected topic. Do not publish.

Allowed generated assets:

- short summary;
- WeChat/social outline;
- CTA snippets pointing to public URLs only;
- claim-boundary checklist;
- internal-link suggestion map.

Forbidden:

- CMS write;
- public posting;
- search submission;
- private URL usage;
- claims about guaranteed major, admission, job, salary, or career outcome.

## Phase 3: Later Content Or Repair PRs

Only after explicit authorization:

- create/update CMS draft package;
- human review;
- claim lint;
- publication gate;
- post-publish smoke;
- separate search submission if authorized.

## Quality Gate

Do not use GSC/seo_intel CTR or impression metrics for this cluster until `GSC-SEO-INTEL-QUALITY-REFRESH-READONLY-01` has a future pass state. Current gate state is blocked.

## RIASEC Result Interpretation Follow-Up

The result inventory found that RIASEC lacks a dedicated result-reading page. Proposed future PR:

`RIASEC-RESULT-INTERPRETATION-CONTENT-PLAN-01`

Scope:

- explain six dimensions and top-three code;
- explain how to use result with majors/courses/jobs;
- make uncertainty and boundary statements quotable;
- no private result URLs;
- no precise recommendation claims.
