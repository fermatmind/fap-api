# FermatMind SEO/GEO Growth Role Playbooks

更新日期：2026-07-15
状态：canonical role router，planning/read-only
运行时影响：无
CMS/GSC/部署影响：无

## 1. 目的

本目录把过去分散的“大而全”扫描提示整理为一套可重复执行的角色系统，用于费马测试（FermatMind）的中英文 SEO、GEO、内容资产、公开运行稳定性和商业漏斗治理。

内部长期目标是：

- 以“免费专业测评 + 免费完整结果页”形成差异化搜索心智。
- 在可验证、边界清楚、CMS/backend 权威的前提下，争取核心中英文测评品类的全球前三可见度。
- 在已覆盖的品类中，形成比 123test、Truity 更完整的“测试 - 解释 - 场景 - 行动 - 复盘”内容与产品闭环。

“全球前三”和“超过竞品”是内部投资与度量目标，不是确定排名承诺，也不是可直接发布的比较级宣传。所有公开主张仍受 claim boundary、公开证据和人工审核约束。

## 2. 为什么需要 9 个执行角色

过去的五套扫描指令反复混合了技术 SEO、搜索数据、竞品内容、CMS 稳定性、产品转化和生产红队。单个超长角色容易出现三类偏移：

1. 用截图、印象或旧数据替代当前权威证据。
2. 把内容建议写进前端 fallback，绕过 CMS/backend authority。
3. 把增长扫描误当成生产发布、GSC 提交或安全审计授权。

成熟运营需要 9 个互相正交的执行角色。角色共享证据和输出合同，但不得互相代替。

| 编号 | 角色文件 | 主要问题 | 默认节奏 |
| --- | --- | --- | --- |
| R1 | `global-seo-geo-growth-lead.md` | 全站优先级、角色编排、90 天组合决策 | 季度或重大节点 |
| R2 | `weekly-opportunity-scan.md` | 本周哪些 URL/query 值得修 | 每周 |
| R3 | `monthly-portfolio-review.md` | 哪些业务面应扩、停、降级或重投 | 每月 |
| R4 | `breakthrough-sprint.md` | 一个品类如何在 2-6 周形成专项突破 | 专项 |
| R5 | `technical-search-authority-audit.md` | 抓取、索引、canonical、schema、feeds 是否一致 | 每周抽检/月度全扫 |
| R6 | `search-analytics-measurement-audit.md` | GSC/GA4/Baidu 数据是否可信、query-page 是否匹配 | 每周/月度 |
| R7 | `content-entity-competitor-audit.md` | 内容、实体、SERP 意图和竞品结构差距 | 月度/专项 |
| R8 | `public-content-stability-observability-audit.md` | CMS/API/缓存/降级是否造成假 404/noindex | 每周/月度 |
| R9 | `commercial-funnel-cro-audit.md` | 免费承诺、测试完成、结果页和回流是否形成商业闭环 | 月度/专项 |

共享治理文件：

- `business-surface-matrix.md`：业务面、权威层、搜索意图、指标和优先级。
- `evidence-output-contract.md`：所有角色必须遵守的证据、数据质量、输出和停止合同。
- `.agents/skills/fermatmind-global-seo-geo-growth-scan/SKILL.md`：Codex 路由器；选择正确角色并保持只读边界。

## 3. 当前基线，禁止使用过期假设

执行任何角色前必须读取当前仓库证据，不能把历史问题当作现状：

- 全站战略：`backend/docs/seo/fermatmind-free-assessment-global-seo-geo-strategy-2026-07-04.md`
- 日/周/月运营：`backend/docs/seo/seo-ops-daily-runbook.md`、`backend/docs/seo/seo-ops-weekly-monthly-review-runbook.md`
- 当前 SEO Ops 收尾：`backend/docs/seo/seo-ops-sop-final-closeout.md`
- MBTI 当前权威状态：`backend/docs/seo/mbti-full-personality-authority-closeout-2026-07-15.md`
- CMS 发布审查：`.agents/skills/fap-api-cms-publish-review/SKILL.md`
- 研究与内容规划：`.agents/skills/fermatmind-seo-research-content-planning/SKILL.md`

截至当前仓库证据，中文 MBTI 的 32 个 Profile 和 20 个 Comparison 已完成治理链路。后续扫描必须验证当前运行状态和 GSC 表现，不能继续按“52 页尚未建设”输出重复建设计划。

业务优先级固定为：L1 MBTI、L2 Big Five、L3 文章/topics/职业推荐/非核心测试。优先级可以通过证据调整投入强度，但不能绕过产品和 authority 边界。

## 4. 角色选择路由

| 用户问题 | 首选角色 | 必要协作角色 |
| --- | --- | --- |
| “这周先修什么” | R2 Weekly | R5、R6、R8 |
| “这个月资源投哪里” | R3 Monthly | R1、R6、R7、R9 |
| “MBTI/Big Five/职业要做全球前三专项” | R4 Breakthrough | R5-R9 |
| “为什么有曝光没点击” | R6 Analytics | R7、R9 |
| “为什么页面时好时坏/noindex” | R8 Stability | R5 |
| “title/schema/sitemap/llms 是否正确” | R5 Technical | R8 |
| “竞品为什么强、我们缺哪些内容” | R7 Content | R6、R9 |
| “免费策略是否真正带来完成和回访” | R9 Funnel | R6 |
| “全站成熟商业化路线图” | R1 Global Lead | 全部 |

生产 readiness/AppSec/隐私红队不属于本目录的增长角色。此类任务应继续使用仓库现有 deploy/security skills 和独立红队指令，不得被增长扫描替代。

## 4A. 旧扫描指令迁移表

| 旧指令主题 | 新角色组合 | 迁移结论 |
| --- | --- | --- |
| 高级 Technical SEO、Content SEO、Search Analytics 审计 | R5 + R6 + R7；全站时由 R1 编排 | 拆开技术事实、搜索数据和内容判断，避免一个角色既定数据又改内容 |
| 跨仓库、只读、planning-only 全站端到端扫描 | R1 + R5 + R8，按问题追加 R6/R9 | 保留跨仓库与只读边界，使用共享 evidence contract 统一输出 |
| Career x Big Five 产品竞争力、SEO/GEO、稳定性与文档治理 | R4 + R7 + R8，业务面由矩阵指定 | 保留为 vertical breakthrough，不再复制一套全站通用规则 |
| 全站公开内容稳定性与 CMS 可观测性 | R8，涉及索引一致性时追加 R5 | 成为独立常驻角色，避免被内容扫描稀释 |
| Production Readiness Review 红队 | 不迁入；继续独立 deploy/SRE/AppSec 程序 | 增长扫描不得替代生产授权、SSH readiness、安全和隐私红队 |

旧提示可保留为历史参考，但新的周/月/专项任务应从本目录路由，避免继续复制超长提示并产生规则漂移。

## 5. 统一执行顺序

每次扫描按以下顺序执行：

1. 确认仓库、分支、日期、locale、国家、设备、时间窗和扫描模式。
2. 读取 `evidence-output-contract.md` 与 `business-surface-matrix.md`。
3. 读取当前代码、authority 文档、CMS/public API 和受控搜索数据。
4. 先验证数据质量与 URL Truth，再做机会判断。
5. 将发现归类为 technical、measurement、content/entity、stability、funnel 或 governance。
6. 仅输出证据支持的结论；未知项写 `Unknown`，禁止补猜。
7. 将建议拆成单一 owner、单一 scope、可验证的 PR/内容包任务。
8. 正文、FAQ、SEO 字段继续走 backend CMS 内容包 -> QA -> dry-run -> 审核 -> 导入。
9. sitemap、llms、indexability、GSC、部署和生产写入必须使用各自独立任务与明确授权。

## 6. 竞品使用边界

竞品用于学习页面体系和用户路径，不用于复制文案或形成未经证明的优越性声明。初始结构参照源：

- [123test personality test](https://www.123test.com/personality-test/)：免费入口、测试目录、研究/说明链接和多语言路径。
- [123test career test](https://www.123test.com/career-test/)：测试、职业解释和职业实体网络。
- [Truity TypeFinder](https://www.truity.com/test/type-finder-personality-test-new)：测试入口、长解释、类型库和商业服务路径。
- [Truity Career Personality Profiler](https://www.truity.com/test/career-personality-profiler-test)：职业测评、方法解释和场景内容。
- [16Personalities free test](https://www.16personalities.com/free-personality-test)：测试、类型、职业、团队和资源导航。

上述内容只代表页面在扫描日的公开呈现；竞品自述的用户数、准确性、免费范围、排名或验证状态不得直接当作已验证事实。

Google 官方边界：

- [AI features and your website](https://developers.google.com/search/docs/appearance/ai-features)：AI Overviews/AI Mode 仍依赖基础 SEO、可索引文本、内部链接和可见内容一致性，不需要特殊 AI schema。
- [Creating helpful, reliable, people-first content](https://developers.google.com/search/docs/fundamentals/creating-helpful-content)：内容应服务真实用户和明确目的。
- [Spam policies](https://developers.google.com/search/docs/essentials/spam-policies)：禁止批量制造低价值、仅为排名存在的页面。
- [Structured data guidelines](https://developers.google.com/search/docs/appearance/structured-data/sd-policies)：结构化数据必须与可见内容一致，且不保证富结果。

## 7. 可复制入口指令

### 每周

```text
使用 fermatmind-global-seo-geo-growth-scan 的 weekly 模式，对 fap-web、fap-api、backend CMS/public API 和受控 GSC/analytics 证据做只读扫描。按业务面和 locale 输出本周 Top 10 机会、技术异常、query-page mismatch、内容/CMS 修复队列和单一 scope PR cards。禁止 CMS/GSC/部署写操作。
```

### 每月

```text
使用 fermatmind-global-seo-geo-growth-scan 的 monthly 模式，复盘 28/90 天中英文组合表现、竞品结构变化、技术与内容债务、免费测试到完整结果的漏斗，并给出未来 30/90 天投资组合。所有指标必须带来源和时间窗。
```

### 专项突破

```text
使用 fermatmind-global-seo-geo-growth-scan 的 breakthrough 模式，为 <业务面/locale/query family> 设计 2-6 周专项。先证明 query owner、运行稳定性、内容差距和转化路径，再给 CMS 内容包、前后端 PR、验证指标与停止条件。不得承诺排名。
```

## 8. Repository Rule Impact

本目录只新增 planning/read-only 文档和一个角色路由 Skill，不改变内容所有权、CMS 发布、public API、SEO 枚举、部署或 GSC 权限。backend/CMS 仍为公开内容和 SEO authority；前端仍只负责消费与渲染。
