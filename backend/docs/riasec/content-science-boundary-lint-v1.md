# RIASEC Content Science Boundary Lint V1

Task: `RIASEC-CONTENT-SCIENCE-00`

This document defines the baseline review contract for the RIASEC/Holland result-page content repair train.

## Scope

This PR establishes lintable scientific and editorial boundaries. It does not rewrite runtime content, import CMS data, change production state, or modify frontend public editorial copy.

## Review Principles

- RIASEC results describe vocational interests and work-activity exploration signals.
- Results must not be written as ability, skill, diagnosis, hiring suitability, admission suitability, salary, or success prediction.
- Occupation names may illustrate work activities, but they are not matches, rankings, recommendations, or fit scores.
- 140Q may add task, environment, and role-context detail. It must not be framed as more accurate than 60Q or as overriding 60Q.
- Feedback, disagreement, and aspirations may guide exploration actions. They must not mutate scores, Holland code, report snapshot, share payload, PDF, or history payload.
- If an asset is missing or unsafe, the backend should omit the section fail-closed. The frontend must not supply fallback public content.

## PR Train Usage

Every later `RIASEC-CONTENT-*` PR should use `content-science-boundary-lint-v1.json` as the baseline. A section PR should:

1. Keep the section inside its own PR scope.
2. Repair scientific claims before style.
3. Remove AI-like repetition, generic encouragement, and deterministic labels.
4. Add specificity through activities, tradeoffs, validation questions, and realistic next steps.
5. Run the section-focused tests plus the baseline lint test.

## Non-Goals

- No CMS write.
- No production import.
- No manual deploy.
- No production deploy.
- No sitemap, llms, canonical, noindex, JSON-LD, SEO runtime, permission, secret, or DB migration change.
