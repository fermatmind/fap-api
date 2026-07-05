# Claim Boundary Review

Target: `/zh/tests/holland-career-interest-test-riasec`

## Verdict

Status: `PASS_WITH_MONITORING`

The current public readback does not show high-risk claims that RIASEC precisely predicts a major, admission result, hiring outcome, salary, clinical state, or career success. Existing language frames the page as free career-interest exploration and states that results are directional reference rather than a promised major or admission result.

## Observed Safe Boundary Signals

- Meta description says: `结果用于方向参考，不承诺专业或录取结果。`
- FAQPage includes a diagnostic-boundary question: `这是诊断吗？`
- The page contains `探索`.
- The page does not contain the inspected high-risk terms `精准` or `推荐`.
- CTAs route to public test start pages, not private claims or report endpoints.

## Allowed Framing

Future backend/CMS-authority copy may safely frame RIASEC as:

- a career-interest exploration tool;
- a structured reference for comparing interest dimensions;
- a way to start course, major, job activity, and career evidence checks;
- a decision-support input that should be paired with curriculum, constraints, practical experience, and human review.

## Forbidden or High-Risk Framing

Future copy must not claim:

- precise career recommendation;
- guaranteed best major;
- admission, hiring, salary, or career-success prediction;
- clinical, medical, or psychological diagnosis;
- official provider affiliation or official-instrument equivalence without source-backed approval;
- reliability, validity, norm, percentile, or sample claims without approved public evidence.

## Diagnostic Answer

The current page is coherent enough that direct mutation is premature. The strongest future improvement is not an emergency repair; it is a separate authority dry-run that makes the boundary easier for answer engines to quote:

`RIASEC is an exploration signal, not a precise career or major recommendation.`

That wording must be reviewed and written in backend/CMS authority, not added as a frontend fallback.
