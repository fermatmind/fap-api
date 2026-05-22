# Chinese Claim Linter Runtime Contract

Task: `CLAIM-LINT-01A`

This contract defines the future backend-owned Chinese claim linter runtime and CI boundary. This PR is docs/generated/test only. It does not activate runtime enforcement, mutate CMS content, rewrite copy, publish content, scan production content, modify fap-web, change sitemap or `llms.txt`, write `seo_intel`, enqueue Search Channel rows, submit URLs, enable scheduler, edit env, or deploy.

## Candidate Input Surfaces

The future linter may inspect candidate public copy from:

- article title, excerpt, body, and editorial package fields
- Research report title, body, methodology, disclaimer, FAQ, and excerpt
- SEO metadata
- FAQ
- JSON-LD text where applicable
- `llms.txt` / AI answer surfaces before publication eligibility
- CTA copy
- career guide, career job, and career recommendation copy
- Big Five, RIASEC, Career Graph, IQ/EQ, MBTI, and test detail surfaces

The linter must run on candidate content packages or controlled fixtures unless a later PR explicitly scopes a safe CMS read. It must not scan production content by default.

## Required States

- `safe`: no risky claim pattern or only bounded safe wording in appropriate context.
- `needs_review`: caution phrase or bounded phrase requiring human review in a sensitive context.
- `blocked`: forbidden phrase or claim pattern that would be unsafe for public/indexable use.

## Severity Mapping

- `P0`: public/indexable claim-unsafe pages, including hiring suitability, clinical/diagnostic claims, salary guarantees, or individual prediction claims.
- `P1`: high-risk SEO metadata, FAQ, `llms.txt`, AI answer, or JSON-LD claim risk.
- `P2`: draft/article body caution needed before publish.
- `P3`: informational wording drift or non-public warning.

Claim severity must not trigger auto-fix, auto-publish, Search Channel enqueue, or URL submission.

## Forbidden / Flagged Phrases

The linter contract must include these exact forbidden or flagged phrases:

- 精准职业推荐
- 最适合职业
- AI 职业规划
- 岗位胜任力
- 招聘适配
- 职业成功率
- 薪资保证
- 个人离职预测
- MBTI决定收入
- MBTI预测离职
- Big Five职业精准匹配
- RIASEC推荐职业
- 智商真实测量
- 临床诊断
- 诊断
- 确诊
- 治疗
- 治愈
- 心理疾病判断

These phrases are especially sensitive in MBTI salary/turnover, career recommendation, hiring, IQ/EQ, Big Five, RIASEC, Career Graph, and clinical-adjacent surfaces.

## Allowed Bounded Phrases

The linter may classify bounded wording as safe only when the surrounding context stays non-deterministic, non-diagnostic, and non-hiring:

- 职业方向参考
- 兴趣信号
- 工作方式倾向
- 探索建议
- 非诊断
- 结果仅供参考
- 自评筛查
- 模型化指数
- 聚合层面
- 方向性趋势
- snapshot-based support
- evidence-backed explanation

Allowed wording must not be combined with guarantees, hiring claims, individual prediction, medical claims, exact IQ claims, or best-fit career claims.

## Runtime Safety Requirements

The future runtime must:

- classify `safe`, `needs_review`, or `blocked`
- map severity to `P0`, `P1`, `P2`, or `P3` where enough context exists
- emit CI-readable JSON
- preserve matched rule evidence without raw private data
- avoid private contact or user-level data
- fail closed for public/indexable claim-unsafe content

The future runtime must not:

- auto-rewrite content
- auto-publish content
- mutate CMS content
- modify fap-web
- enqueue Search Channel rows
- submit URLs
- write Observation Queue rows in this contract PR
- write `seo_intel`
- change sitemap or `llms.txt`
- scan production content without explicit scope

## Claim Boundary Rules

The linter must block or escalate language implying:

- MBTI determines salary
- MBTI predicts individual turnover
- MBTI causes income
- MBTI is a hiring or promotion tool
- hiring suitability by MBTI
- best-fit jobs by MBTI
- precise career recommendation
- salary guarantee
- individual prediction
- clinical / diagnostic framing
- exact IQ or real intelligence measurement claims
- Big Five or RIASEC precise job matching

Bounded language may frame results as exploratory, aggregate, model-index based, directional, self-report, non-diagnostic, and not individual prediction.

## Next Task

Next task: `CLAIM-LINT-01B`
