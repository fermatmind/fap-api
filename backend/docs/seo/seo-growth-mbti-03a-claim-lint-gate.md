# MBTI Claim Lint Gate

Task: SEO-GROWTH-MBTI-03A

Type: docs/generated/test only.

This contract defines the MBTI-specific claim lint gate before Search Channel planning, Digital PR Wave 2, Content/Internal Link Wave 1, or growth experiment review. It does not scan production content, does not mutate CMS, does not modify fap-web, does not send Digital PR, and must not auto-rewrite copy.

## Required Surfaces

- MBTI research salary/turnover report.
- MBTI test page metadata/FAQ/JSON-LD.
- MBTI result/report/paywall copy.
- MBTI topic/article snippets.
- internal link anchor text.
- Digital PR pitch copy if later used.
- career/job-fit adjacent MBTI wording.

## Forbidden Claims

- MBTI决定收入.
- MBTI预测离职.
- 薪资保证.
- 个人离职预测.
- 招聘适配.
- 职业成功预测.
- 精准职业推荐.
- 最适合职业.
- AI 职业规划.
- 岗位胜任力.
- 诊断.
- 确诊.
- 治疗.
- 治愈.

## Allowed Bounded Language

- 模型化指数.
- 聚合层面.
- 方向性趋势.
- 非诊断.
- 结果仅供参考.
- 职业方向参考.
- 工作方式倾向.
- 探索建议.
- discussion resource.
- modeled signal.
- aggregate trend.
- not hiring advice.
- not salary guarantee.
- not individual prediction.

## States

- `safe`: bounded language and no blocked phrases.
- `needs_review`: claim-sensitive phrasing that needs human review before public/search/outreach use.
- `blocked`: forbidden claim or high-risk overclaim.

## Severity Mapping

- P0: claim-unsafe public/indexable page.
- P1: high-risk SEO metadata / FAQ / JSON-LD / llms claim risk.
- P2: draft/article body needs caution.
- P3: non-public wording drift.

## Gate Rules

- Claim lint flags or blocks copy; it must not auto-rewrite.
- Claim unsafe URLs cannot enter Search Channel planning.
- Claim unsafe pitch copy cannot enter Digital PR Wave 2.
- Claim unsafe content/internal link candidates cannot enter Wave 1.
- MBTI, Big Five, RIASEC, and Career Graph must not be framed as precise career recommendation, hiring suitability, salary prediction, or career-success prediction authority.

## Next Task

After this PR merges, continue with `SEO-GROWTH-MBTI-03B｜Search Channel Canary Wave Plan`.
