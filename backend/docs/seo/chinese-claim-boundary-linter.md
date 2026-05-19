# Chinese Claim Boundary Linter Contract

## Purpose

CLAIM-LINT-00 defines a Chinese SEO claim boundary contract for future content, Research Asset, and Search Channel review.
This PR is docs/spec/test only. It does not activate runtime linting, change frontend copy, publish content, alter sitemap or `llms.txt`, run collector writes, enable scheduler, or deploy services.

## Forbidden Claim Classes

Chinese copy must be blocked or escalated for review when it implies:

- 诊断
- 确诊
- 治疗
- 治愈
- 真实智商
- 权威认证
- 精准推荐
- 最适合职业
- 岗位胜任力
- 招聘适配
- 职业成功率
- AI 职业规划
- 心理疾病判断

These phrases are especially risky when attached to RIASEC, Big Five, Career Decision, MBTI, or other self-assessment products.

## Allowed Safer Wording

Safer wording may be allowed when the page context remains non-diagnostic and non-deterministic:

- 自评筛查
- 非诊断
- 结果仅供参考
- 在线估测
- 置信区间
- 职业方向参考
- 探索建议
- 兴趣信号
- 工作方式倾向
- snapshot-based support

Allowed wording must not be combined with guarantees, medical claims, hiring claims, exact IQ claims, or full career recommender claims.

## Boundary Rules

- RIASEC and Big Five career semantics remain shallow/partial assets.
- Career Decision support must remain exploratory unless a separate approved runtime expands it.
- Research Assets must include claim review before sitemap, `llms.txt`, or Search Channel Queue eligibility.
- Draft, private, noindex, and claim-unsafe pages must not enter search submission queues.
- Frontend runtime copy must not be changed by this PR.

## Future Linter Behavior

A future inactive linter may classify copy into:

- `blocked_claim`
- `needs_review`
- `allowed_safer_wording`
- `neutral`

The linter should return matched phrase, class, severity, and suggested safer wording. It must not auto-publish, auto-edit CMS content, submit URLs, or mutate runtime copy.

## Stop Conditions

Stop if any implementation:

- expands RIASEC, Big Five, or Career Decision claims into full recommendation claims
- changes frontend runtime copy
- changes publishing behavior
- changes sitemap or `llms.txt`
- enables runtime activation without approval
- auto-publishes or auto-edits CMS content
- triggers search submission

## Next Task

Next task: CONTENT-OPS-01.
