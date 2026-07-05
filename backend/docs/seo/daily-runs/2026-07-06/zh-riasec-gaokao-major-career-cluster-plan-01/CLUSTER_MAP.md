# Chinese RIASEC / Gaokao / Major / Career Cluster Map

## Hub And Owner Pages

| Layer | URL | Role |
| --- | --- | --- |
| Money owner | `/zh/tests/holland-career-interest-test-riasec` | Owns direct `霍兰德职业兴趣测试`, `RIASEC测试`, `职业兴趣测试` intent. |
| Explainer | `/zh/articles/riasec-holland-career-interest-test-explained` | Owns `RIASEC是什么`, `霍兰德职业兴趣是什么`, and model explanation intent. |
| Boundary explainer | `/zh/articles/holland-career-interest-test-can-and-cannot-tell-you` | Owns "准吗 / 能告诉你什么 / 不能告诉你什么" intent. |
| Comparison | `/zh/articles/career-interest-vs-personality-test-differences` | Owns career-interest vs personality-test distinction. |
| MBTI comparison | `/zh/articles/mbti-vs-holland-career-choice` and `/zh/articles/college-major-choice-holland-mbti-career-test` | Owns MBTI + Holland combined decision support intent. |

## Gaokao / Major Scenario Pages

| Scenario | URL | User job | Recommended CTA target |
| --- | --- | --- | --- |
| Score to shortlist | `/zh/articles/gaokao-score-major-shortlist-riasec-checklist` | User has score/rank and needs a major shortlist method. | RIASEC test owner plus course evidence checklist. |
| Parent conflict | `/zh/articles/gaokao-major-choice-parent-conflict-riasec-course-checklist` | User needs a structured discussion with parents. | RIASEC test owner plus comparison article. |
| Unwanted adjustment | `/zh/articles/gaokao-major-adjustment-unacceptable-major-checklist` | User is deciding whether an adjusted major is acceptable. | RIASEC test owner plus unwanted-major transfer/repeat pages. |
| Hot major fit | `/zh/articles/hot-major-fit-riasec-course-career-checklist` | User wants to evaluate a popular major without hype. | RIASEC test owner plus career guide routes. |
| Math / computer / AI concern | `/zh/articles/math-not-good-computer-ai-major-course-riasec-checklist` | User worries a major is mismatched to ability or interest. | RIASEC test owner plus course task checklist. |
| Repeat or stay | `/zh/articles/unwanted-major-repeat-or-stay-riasec-decision-checklist` | User weighs repeating exam vs staying in major. | RIASEC test owner plus transfer plan page. |
| Transfer plan | `/zh/articles/unwanted-major-adjustment-riasec-transfer-plan` | User needs a staged transfer/exploration plan. | RIASEC test owner plus career direction guide. |

## Career Direction Support

| URL | Role |
| --- | --- |
| `/zh/articles/career-confusion-test-map` | Routes confused users to tests and next steps. |
| `/zh/articles/major-career-mismatch-job-search-skills-plan` | Bridges major mismatch into job-search/skill planning. |
| `/zh/career/guides/how-to-choose-college-major` | Career guide layer for major choice. |
| `/zh/career/guides/how-to-find-right-career-direction` | Career guide layer for direction exploration. |
| `/zh/career` and `/zh/career/guides` | Career hub/guides aggregation. |

## Internal Link Direction

Recommended direction:

- scenario pages -> RIASEC test owner;
- scenario pages -> relevant explainer/boundary page;
- result-reading page when created -> RIASEC test owner and scenario pages;
- career guide pages -> RIASEC test owner when career-interest exploration is relevant;
- RIASEC test owner -> selected high-intent scenario pages only, not every career job URL.

Avoid:

- sending direct test intent to articles;
- turning career job pages into RIASEC evidence without explicit backend authority;
- linking private result/report/share URLs into public pages;
- using "recommended major/job" phrasing without review.
