# FermatMind Business Surface Matrix

状态：shared planning contract
更新日期：2026-07-15

## 1. 使用方式

每个增长扫描先用本矩阵确定业务面、query owner、authority、优先级和安全边界。不得因为某个 URL 有曝光就跳过产品优先级、内容权威或私人数据边界。

## 2. 全站业务矩阵

| Surface | Priority | Primary intent | Public owner | Growth path | Core KPI | Hard boundary |
| --- | --- | --- | --- | --- | --- | --- |
| MBTI test | L1 | 免费测试、16 型人格测试 | backend test/catalog + frontend product flow | SERP -> start -> complete -> result -> profile | impressions, CTR, start, completion, result view | private attempts/results noindex |
| MBTI personality hub | L1 | MBTI/16 型/32 人格目录 | backend landing/CMS | hub -> profiles/comparisons -> test | non-brand visibility, profile distribution | no frontend editorial fallback |
| MBTI 32 profiles | L1 | 类型定义、A/T、职业/关系/成长 | backend personality CMS/API | answer -> related compare -> test/career | query-page match, top 20 share, assisted starts | no official MBTI affiliation claim |
| MBTI comparisons | L1 | A/T 差异、type vs type | backend comparison CMS/API | direct answer -> judgment table -> profiles/test | comparison visibility, citation readiness | no deterministic identity claims |
| Big Five | L2 | Big Five/OCEAN 测试与特质解释 | backend scale/profile CMS/API | test -> result -> traits -> career | category visibility, completion, interpretation | no unsupported norms/validity |
| Enneagram | L3 until reprioritized | 九型测试与动机解释 | backend scale/profile CMS/API | test -> result -> types | visibility, completion | avoid diagnosis/determinism |
| RIASEC/Holland | L2/L3 by launch gate | 职业兴趣、专业/职业探索 | backend flagship scale + career graph | test -> code -> career/major | start, result, career exploration | one flagship, no parallel stack |
| IQ/EQ/non-core tests | L3 | 能力/情绪自评 | backend test catalog/content | test -> result -> method/boundary | qualified starts, trust | no ability/future guarantee |
| Career directory/detail | L2/L3 | 职业是什么、适合谁、路径、技能 | backend career authority | search -> career -> test/adjacent careers | indexed coverage, career depth, assisted tests | no salary/hiring guarantee |
| Articles/topics | L3 support | 问题、场景、方法解释 | backend Article/Topic CMS | article -> test/profile/career | non-brand entry, assisted conversion | no local content files |
| Trust/method/help/policy | Trust layer | 方法、隐私、边界、支持 | backend content pages | trust -> test/result | trust assists, support deflection | no unsupported badges/endorsement |
| Enterprise/team | Conditional | 团队/组织使用 | approved backend product/CMS surface | business page -> qualified lead | qualified lead, activation | no hiring/screening claims |

## 3. MBTI 资产图

当前受治理的中文公开资产为 52 条：32 个 A/T Profile + 20 个 Comparison。扫描时先验证现状，不重复生成已存在页面。

```text
MBTI test
  -> MBTI personality hub
     -> 32 A/T profiles
        -> 16 A/T comparisons
        -> hot cross-type comparisons
        -> career / relationship / growth sections
     -> MBTI test
```

每个节点的理想职责：

| Node | Must answer | Must link to |
| --- | --- | --- |
| Test | 测什么、是否免费、结果是什么、边界是什么 | hub、对应结果类型、方法/隐私 |
| Hub | 16 型与 32 A/T 的关系、如何浏览、如何测试 | 全部 profiles、热门 comparisons、test |
| Profile | 一句话定义、适合/不适合、误解、A/T、职业/关系/压力 | paired A/T、相关 comparisons、test、career |
| Comparison | 最大区别、快速判断、误判原因、真实场景、边界 | 两侧 profiles、相关 comparisons、test |

## 4. Locale 与 query owner

- `zh-CN` 和 `en` 必须分别建立 query owner；翻译对等不等于搜索意图对等。
- 一个核心 query family 只能有一个 primary owner，支持页面必须明确辅助意图。
- 同一 query 在不同国家、设备或 SERP 形态下可以有不同 owner，但证据中必须标明维度。
- 缺少对应 locale authority 时标记 `authority_gap`，不得由前端生成译文或正文兜底。
- 私人 result、attempt、report、history、order、checkout、payment、recovery 和 token URL 永远不是 query owner。

## 5. 机会优先级

默认优先级从高到低：

1. 技术异常导致已授权页面假 404、假 noindex、canonical/schema/feed 不一致。
2. L1 MBTI 中排名 4-15、曝光增长、owner 明确的 query-page 机会。
3. L2 Big Five/RIASEC/Career 中已有产品与 authority 支撑的高意图机会。
4. 中英文 parity 缺口、孤页和已证明的内容/实体缺口。
5. 没有真实需求、authority 或独特价值证据的扩页建议。

## 6. 商业成熟度检查

每个业务面按 0-4 级评估，不能只看页面数量：

| Level | Definition |
| --- | --- |
| 0 | 没有 owner、authority 或产品路径 |
| 1 | 有页面/产品，但 URL Truth、内容或测量不完整 |
| 2 | 可索引且可测量，基本 query-page match 成立 |
| 3 | 有稳定内容图谱、内部链接、转化和周期复盘 |
| 4 | 中英文组合稳定，持续产生品牌/非品牌需求与可验证商业价值 |

成熟商业化目标是逐面从 2 提升到 3/4，不是批量增加 URL。

## 7. 输出要求

任何角色引用本矩阵时，至少输出：

- surface、priority、locale、query family、primary owner。
- authority state、indexability state、measurement state。
- 当前成熟度与证据。
- 推荐 owner（frontend/backend/CMS/analytics/SRE/content）。
- 单一 scope 下一步与验证指标。
