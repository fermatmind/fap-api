# FermatMind Growth Evidence and Output Contract

状态：mandatory shared contract
更新日期：2026-07-15

## 1. 证据优先级

角色扫描必须使用以下优先级，不得用低层证据覆盖高层 authority：

1. 当前 `fap-api`、`fap-web` 代码、测试和仓库规则。
2. backend CMS 数据、public API、URL Truth、发布/indexability/readmodel 状态。
3. 通过仓库 data-quality gate 的 GSC、GA4、Baidu 和受控 SEO readmodel。
4. 当前线上 HTTP/HTML、sitemap、llms、JSON-LD 和浏览器观察。
5. 公开竞品页面、官方搜索文档和人工研究记录。
6. 截图、手工抄录和历史聊天，只能作为线索，不能作为最终 authority。

若证据冲突，必须报告冲突并保守决策，不得选择最符合预期的数字。

## 2. 每项指标的最小元数据

每个 impressions、clicks、CTR、position、conversion 或 coverage 数字必须附带：

- source/system。
- property/view/readmodel。
- date range 与 comparison range。
- fetched_at 与 timezone。
- locale/country/device/search type/filter。
- row completeness、sampling/threshold/lag 状态。
- 是否可与上一周期直接比较。

缺少任一关键字段时，指标状态为 `unverified`，不能驱动生产写入。

## 3. 证据状态

| State | Meaning | Allowed use |
| --- | --- | --- |
| `verified` | 由当前 authority 或通过 gate 的数据直接证明 | 可用于排序和 PR/内容包建议 |
| `observed` | 当前公开页面/竞品/浏览器可见，但不是内部 authority | 可用于结构分析和复核线索 |
| `inferred` | 从多个证据推断，尚无直接证明 | 必须写明推理和验证方式 |
| `unknown` | 没有足够证据 | 保留为空，不得补猜 |
| `blocked` | 权限、质量门禁或外部依赖阻止验证 | 输出 blocker 与 owner |

## 4. Finding 合同

每个 finding 必须包含：

```yaml
id: stable-id
severity: P0|P1|P2|P3
surface: business surface
locale: zh-CN|en|other
url_or_family: sanitized public reference
evidence_state: verified|observed|inferred|unknown|blocked
evidence:
  - source
  - time_window
  - concrete observation
user_or_search_impact: why it matters
root_cause_or_hypothesis: proven cause or explicitly labeled hypothesis
authority_owner: frontend|backend|CMS|analytics|SRE|content
recommended_scope: one repair scope
acceptance: measurable checks
non_authorizations: writes/deploy/search actions not allowed
```

P0/P1 必须有直接证据；仅凭猜测不得升级为 P0/P1。

## 5. 决策规则

- 排名 1-10 且 CTR 明显偏低：优先提出 title/meta 与 SERP 意图假设，先验证品牌词和 SERP module 影响。
- 排名 8-20 且曝光增长：优先检查直接答案、FAQ、实体覆盖、内部链接和页面稳定性。
- query 意图与 owner 不匹配：调整 query owner 或 CMS 模块/标题结构，不在前端补正文。
- 无曝光：先检查索引、canonical、内部链接、重复风险和需求证据，不立即重写。
- 技术异常：可立即创建 owner-scoped repair，不必等待完整观察周期。
- JSON-LD、sitemap 或 llms 存在不等于内容质量、GEO 引用或排名保证。
- 任何排名/引用改善都视为外部结果，不得承诺确定时间或名次。

## 6. 统一输出包

正式扫描至少输出：

1. Executive summary：不超过 10 条，区分事实、推断和未知。
2. Evidence inventory：数据源、时间窗、质量状态和缺口。
3. Surface scorecard：按业务面/locale 的技术、内容、测量、稳定性、漏斗成熟度。
4. Opportunity matrix：query family、owner page、证据、潜力、风险、下一步。
5. Technical/stability blockers：先于内容扩张。
6. Content/entity gaps：可见内容与 CMS authority 差距。
7. Funnel gaps：免费承诺到结果与回流的断点。
8. 7/30/90 day roadmap：每项只有一个 owner 和 scope。
9. PR/content-package cards：依赖、允许路径、测试、停止条件。
10. Blocked/unknown ledger：不得删除未解决的不确定项。

同时提供结构化 JSON，最小顶层字段为：

```json
{
  "run": {},
  "sources": [],
  "scorecard": [],
  "findings": [],
  "opportunities": [],
  "roadmap": [],
  "blocked_or_unknown": []
}
```

## 7. 竞品证据合同

- 记录 URL、抓取日期、locale、页面类型和可见结构。
- 区分竞品自述与可独立验证事实。
- 不复制文案、表格、测试题、报告、品牌素材、评价或专有标签。
- 不把竞品排名、价格、用户数、评分或“准确性”写成事实，除非有当前可引用的权威来源。
- 输出“结构启发”和“FermatMind 原创价值路径”，禁止生成未经审核的比较优越性文案。

## 8. 硬边界

本合同不授权：

- CMS/database 写入、导入、发布或 promotion。
- sitemap、llms、canonical、robots、JSON-LD 或 indexability mutation。
- GSC sitemap 提交、URL Inspection、Request Indexing、IndexNow 或其他 search mutation。
- 生产部署、重启、解锁、缓存清理或进程操作。
- 读取或记录私人 result、attempt、report、order、payment、history、token 或个人身份数据。
- 在前端新增 CMS-backed editorial fallback。

需要上述动作时，扫描只生成独立、可审查、fail-closed 的后续任务，并等待对应明确授权。

## 9. 数据最小化与日志

- 只存聚合、去标识化和业务必要字段。
- URL 清单必须排除私人路径与参数。
- 不把 Cookie、OAuth token、账号、原始搜索 payload 或个人结果写入文档、PR、日志或 artifacts。
- CSV 文本必须防公式注入。
- 证据过期时标记 stale，不静默沿用。

## 10. 完成标准

扫描只有在以下条件满足时才算完成：

- 每个高优先级结论都有来源和时间窗。
- query family 有明确 owner 或明确 authority gap。
- 内容建议指向 CMS/backend 资产流程。
- 技术、内容、稳定性和漏斗问题已分 owner。
- 未执行任何未授权外部写操作。
- 输出可被下一次周/月扫描复用和对比。
