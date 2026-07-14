# Big Five Authority V2 × Career 后端技术复盘

日期：2026-07-15

仓库：`fap-api`（公开内容、CMS、发布状态与 public API 权威）

范围：Big Five Authority V2 的 38 个串行 PR、后续受控生产导入链路，以及职业板块的 Big Five 消费边界

状态：技术文档与 skill guardrail 复盘；本文件不授权任何部署、迁移、CMS 写入、内容 promotion、indexability、sitemap、LLMS、媒体、缓存或搜索动作

## 1. 结论

Big Five Authority V2 已完成从逐页 benchmark、内容合同、114 个公开人格候选页、100 个双语 Article draft、SEO/GEO gate，到 collision-safe production draft import 和只读 runtime closeout 的完整链路。

这次完成的是“后端权威内容进入隔离工作区”，不是“231 个资产全部公开上线”：

- 106 个新 primary identity 保持 draft/fail-closed；
- 125 个既有 published identity 的公开字段和 published revision 保持不变；
- 229 个 working/draft revision 进入隔离工作区；
- 没有 content promotion、public release、indexability、sitemap、LLMS、media、cache 或 search mutation；
- PR38 记录了真实公开 runtime 缺陷，但没有把缺陷修复偷渡进 closeout scope。

职业板块必须据此采用一个硬规则：

```text
Big Five working/draft revision
!= published public projection
!= career fit/recommendation input
```

Big Five 在职业场景中只能作为补充的 trait/work-style 解释语言。RIASEC 仍是职业兴趣主信号；Big Five 不得成为精准职业匹配器、岗位筛选器或结果保证器。

## 2. 两条 Big Five V2 链路必须分开

仓库中存在两条相关但不同的 Big Five V2 技术链路。旧文档如果只写“Git-backed runtime 是 source of truth”或“CMS 不是 runtime owner”，容易被扩大解释。

| 链路 | 负责什么 | 权威来源 | 职业侧可否直接消费 |
| --- | --- | --- | --- |
| Big Five result/report runtime | 私有测评结果的 selector、composer、route matrix、跨 surface payload 和 rollout governance | Git-backed release snapshot + runtime/import gates | 不可直接当成公开职业匹配输入；私人分数、attempt/report、selector trace 不得进入公开职业面 |
| Big Five public content authority | personality hub/domain/range/facet、Article、Topic、test landing、methodology/trust、metadata 与发布状态 | fap-api CMS/public API 的 published projection | 仅可消费已发布且 public-safe 的 projection；不得读取 working/draft revision |
| Career authority | Career job/guide/recommendation、发布 ledger、runtime publish projection、SEO/public API | fap-api Career authority | 可把已发布 Big Five 解释作为补充内容证据，但不能据此生成确定性排名或录用结论 |

因此：结果报告链路继续由 Git-backed release snapshot 控制；公开人格与职业内容链路继续由 backend/CMS published authority 控制。两者不能通过 frontend fallback、临时 JSON、generated artifact 或 working revision 相互替代。

## 3. 38 个 PR 的工作分解

38 个 PR 严格串行完成，按职责可归纳为以下八段：

| PR 范围 | 数量 | 交付结果 |
| --- | ---: | --- |
| `BENCHMARK-01` | 1 | 固化 127 个 canonical（114 personality、9 article、2 test、2 topic）和 10 个 zh legacy 301 alias 的逐页 benchmark，并锁定后续 ownership |
| `INTEGRITY-GATE-02`–`EDITORIAL-GATE-06` | 5 | 修复完整性问题；建立 visible evidence public contract、fap-web trust renderer、双语 source/claim ledger、原创与人工审核状态 gate |
| `HUB-07`–`TEST-LANDING-20` | 14 | 生成 114 个 personality candidate 和 2 个 test landing candidate；覆盖 hub、domain、facet hub、range/legacy、30 facets；全部保持非确定性、非诊断、非招聘边界 |
| `ARTICLE-IA-21`–`TECHNICAL-TRUST-23` | 3 | 锁定 50 个双语文章 intent；刷新 9 篇既有文章和 2 个 topic hub；准备 4 个 methodology/trust content page draft |
| `ARTICLE-CORE-MODEL-24`–`ARTICLE-RESEARCH-BRIEFINGS-33` | 10 | 10 个 editorial wave，每个 5 个主题 × EN/ZH，合计 100 个 Article draft；保留 raw、skeptical review、repair、final、source mapping 和 QA |
| `MEDIA-OG-34`–`SEO-GEO-AUTHORITY-36` | 3 | 只映射已批准 Media Library authority；重建 link/canonical/legacy alias graph；建立逐页 visible-evidence、schema、sitemap 与 LLMS eligibility gate |
| `RELEASE-GATE-37` | 1 | 聚合 231 个 asset，完成 schema/source/duplicate/bilingual/private-boundary validation、zero-write dry-run、逐页 eligibility 和 exact-SHA authorization packet |
| `RUNTIME-CLOSEOUT-38` | 1 | 在受控生产导入后执行只读 HTML/metadata/schema/media/link/redirect/sitemap/LLMS/private-boundary/visual QA，并如实记录 PASS/FAIL/UNKNOWN |

关键内容覆盖：

- personality canonical candidate：114；
- test landing candidate：2；
- existing Article：9；
- Topic：2；
- methodology/trust ContentPage：4；
- new bilingual Article draft：100；
- 聚合 package asset：231。

所有 Codex skeptical review 都只是自动化审阅证据，不等于具名人工审核。缺少真实 reviewer 的资产继续保持 `pending_manual_review` 或 draft 状态。

## 4. 为什么增加了三个 ad-hoc 后端能力

PR37 的本地/test DB 计划最初假设 `CREATE=231, UPDATE=0`。生产只读 identity preflight 显示实际状态为：

```text
new identities = 106
existing published identities = 125
```

直接执行原 writer 会产生 identity collision，或迫使已有 published 记录被 update；两者都不满足“保留公开 runtime”的要求。因此后续增加了三个隔离能力：

1. 最小 draft import writer：先把所有 package identity、SHA、计数、状态和写入面锁进 fail-closed preflight。
2. Collision-safe draft revision writer：新 identity 创建 draft primary；既有 published identity 只创建 working revision，并保留 public fields 和 `published_revision_id`。
3. Schema-only production deploy mode：只允许一个精确批准的 pending migration，禁止普通 deploy hook 顺带执行内容 baseline import、seed、业务/公开缓存 warming 或搜索动作。

对应的核心实现：

- `backend/app/Console/Commands/PersonalityBigFiveAuthorityV2CollisionSafeDraftImport.php`
- `backend/app/Services/BigFive/AuthorityV2/ReleaseGate/BigFiveAuthorityV2CollisionSafeDraftRevisionWriter.php`
- `backend/database/migrations/2026_07_15_000100_add_big_five_authority_v2_draft_revision_workspaces.php`
- `.github/workflows/deploy-production.yml`
- `deploy.php`

迁移为 revision 表/字段增加 authority identity、source/package SHA、workflow state 和 working/published pointer。`down()` 故意保持 forward-only，避免误删 lineage 或公开指针。

## 5. 生产导入的不可变证据

### 5.1 锁定身份

| 项目 | 值 |
| --- | --- |
| Writer deploy SHA | `d023ddc2819ce6f2a271795c6e0b5a807c364ba1` |
| PR37 merge SHA | `af99ac41406a2967b9f4778dc9da07b920bfbb7f` |
| Authority package SHA-256 | `fb67edc033e679da3f134b34db30901465c7b44e0585818b23613fab83bf9162` |
| Draft import package SHA-256 | `80f95a73d497f28a74197b5af7dc1849af35ec9c15958ac898b29b669b997154` |
| Collision contract SHA-256 | `fffcd07c97a7adbefc9d63c03b6523233f4b9f3c6a0c5733249da591254f3b49` |
| Read-only preflight fingerprint | `2a5e26986fae52e04481623d6b0ed166876091116861a942f39638f0ea807a9f` |
| Existing-public fingerprint | `7d4958338a120933eb8b325d8452cfa836ff0f02e40288562ec44e60723ac273`（transaction 前后相同） |

### 5.2 Transaction 结果

| Surface | New primary draft | Existing identity revision | Revision created |
| --- | ---: | ---: | ---: |
| Article | 100 | 9 | 109 |
| ContentPage | 4 | 0 | 4 |
| LandingSurface/PageBlock | 2 | 0 | 0 |
| PersonalityPublicContentAsset | 0 | 114 | 114 |
| TopicProfile | 0 | 2 | 2 |
| **Total** | **106** | **125** | **229** |

另外：

- 104 个新 revision-managed primary 获得 working revision；
- 125 个既有 published record 的 working pointer 指向新隔离 revision；
- 125 个既有 record 的 published pointer 和公开内容字段未被覆盖；
- transaction 在任意 identity、count、schema、package、SHA、fingerprint 或 public-runtime drift 时整体 abort。

### 5.3 明确为零的动作

```text
public content overwrite = 0
content promotion = 0
public release = 0
indexability change = 0
sitemap change = 0
LLMS change = 0
media write = 0
cache invalidation/warming = 0
search submission = 0
```

## 6. Runtime closeout：安全边界通过，公开质量未 close

PR38 的最终结论是：

```text
FAIL_CLOSED_PUBLIC_RUNTIME_FINDINGS_RECORDED
```

“FAIL”不是 draft 隔离失败。关键安全结果是：

- 127/127 既有公开 route 返回 200；
- 104/104 新 draft route 保持 noindex，且不在 sitemap、`llms.txt`、`llms-full.txt`；
- 104 个 draft route 当前返回 200 soft-404 shell，而不是预期的 404/410；
- 10/10 zh legacy alias 返回精确 301；
- private feed leak 为 0；
- Big Five lookup/flag API 返回 200。

公开质量 findings 共 334 个，覆盖全部 231 个 package asset：

| Finding | Count |
| --- | ---: |
| Media/OG | 116 |
| HTTP soft-404 | 104 |
| Visible date | 82 |
| FAQPage JSON-LD | 9 |
| Visible reviewer | 7 |
| JSON-LD | 4 |
| Visible author/source/hreflang/LLMS | 各 3 |

另外，`/en/topics/big-five` 仍停留在 loading skeleton，并观察到 49 个 console error；抽样既有 Article 的 hero media 为空。这些均是后续独立 repair scope，不能被解释为“Authority V2 已完全公开发布”。

## 7. 职业板块的 Big Five 消费合同

### 7.1 信号优先级

```text
RIASEC interests        = primary career-interest signal
work activities/values = occupation evidence
Big Five               = supplementary trait/work-style explanation
MBTI                    = supplementary preference language
```

Big Five 可以帮助读者观察工作环境、反馈方式、节奏、社交密度、结构偏好和压力反应倾向；不能证明某人适合或不适合某职业。

### 7.2 允许的数据源

Career fit 或 career-personality bridge 只可消费：

- Career runtime publish projection 已允许的 canonical occupation；
- frozen/PASS career identity、work-activities、RIASEC/work-style evidence；
- Big Five backend public API 返回的已发布、public-safe、locale-matched projection；
- 可见且已通过 claim/source/reviewer/date permission gate 的字段；
- 不含私人 attempt/report/user/order/payment 标识的解释性输入。

### 7.3 禁止的数据源

- `working_revision_id` 指向的 snapshot；
- draft、review、pending_manual_review 或未 promotion 的 Authority V2 asset；
- PR37 package、generated artifact 或本地 baseline 作为 runtime authority；
- 私人 score vector、percentile、item answers、selector trace、attempt/report URL；
- frontend fallback copy、本地职业排名或未经 backend projection 批准的 occupation；
- 仅因 primary record 存在、route 返回 200 或 sitemap 出现就推断“已发布”。

### 7.4 允许的输出

- work-style signals to reflect on；
- environment/feedback/structure questions；
- possible friction or mismatch cues；
- examples to explore；
- starting point, not decision；
- 引导用户结合兴趣、技能、价值观、经验和现实约束继续验证。

### 7.5 禁止的输出

- `best career for you`、精准职业匹配、确定性 occupation ranking；
- job-fit score 被描述为客观真相；
- hiring、promotion、admissions、income、success 或 placement 保证；
- 把 neuroticism 等同心理疾病，把任一 trait 高低写成能力或人格优劣；
- 用 Big Five 取代 RIASEC、真实工作活动证据或受监管职业资格要求；
- trait × career、trait × problem 或 career recommendation 的冻结 pSEO 网格。

### 7.6 Revision gate

所有未来 Big Five × Career adapter 必须显式验证：

```text
primary status is public/published
AND published_revision_id resolves
AND selected payload comes from published revision/public projection
AND working_revision_id is never selected implicitly
AND locale and authority asset identity match
AND claim/source/reviewer/date permissions pass
AND career runtime publish projection allows the occupation
```

任一条件不满足时 fail closed：不生成 fallback fit 文案，不扩大 discoverability，不自动退回旧 generated package。

## 8. 技术复盘

### 做对的部分

- 把 benchmark、integrity、contract、editorial、content、media、link、SEO、release 和 runtime closeout 拆成单一 scope，避免“内容生成即上线”。
- production identity mismatch 先只读诊断，没有把 125 个 existing published record 当作 update target。
- 用 revision workspace 解决 collision，而不是覆盖 primary public fields。
- 把 deploy 与 schema-only deploy 分离，禁止 migration 顺带触发 seed、baseline import 和公开缓存任务。
- 所有受控生产动作都锁定 exact SHA、package SHA、count、fingerprint 和精确授权短语。
- PR38 如实记录 FAIL/UNKNOWN，没有为了宣布完成而伪造 PASS。

### 暴露的问题

- release package 的本地 `CREATE=231` 假设没有在生产 identity preflight 前建模 existing identity collision；未来 package 应在 authorization packet 中原生表达 `primary_create`、`existing_revision`、`revision_create` 三类动作。
- “imported”容易被误写为“published”。未来所有状态输出应固定使用 `draft imported`、`working revision created`、`published projection unchanged` 等精确术语。
- 旧 Big Five platform summary 没有清晰分开 result/report runtime 与 public editorial authority。
- career-fit skill 没有显式规定 revision selection，可能让 agent 把 working draft 当成已发布人格证据。
- runtime closeout 对 soft-404、topic skeleton、OG/date/schema 可见性暴露了 package QA 与真实 consumer/render QA 之间的差距。

## 9. 后续工作顺序

后续均需独立 scope，不属于本次文档 PR：

1. 修复 draft route 200 soft-404，使 withheld content 返回明确 404/410 或同等 fail-closed contract。
2. 修复 `/en/topics/big-five` loading skeleton 和 console errors。
3. 分开处理 Media/OG、visible date、author/reviewer/source、hreflang、FAQ/Article/Breadcrumb JSON-LD findings。
4. 完成人工 review 与 promotion 设计；任何 promotion/public release 仍需新的 exact production authorization。
5. 在 Big Five × Career 独立 scan 中验证已发布 public projection、Career runtime publish projection 和 claim permissions；在此之前保持 `explanation_only`，不得启动 matcher/pSEO/recommender。
6. 为 importer 增加可复用的三类 identity plan contract，避免后续大型内容包重复经历 231-vs-106/125 的建模偏差。

## 10. 关联文档与证据

后端：

- `docs/big5-v2-platform-summary/big5_v2_platform_architecture_summary_v1.md`
- `docs/runbooks/big-five-cms-staging-import.md`
- `generated/big-five-authority-v2/big5-authority-v2-release-gate-37/`
- `generated/big-five-authority-v2/big5-authority-v2-collision-safe-draft-revision-writer/`
- `backend/tests/Feature/SEO/BigFiveAuthorityV2CollisionSafeDraftRevisionWriterTest.php`

前端/consumer 证据：

- `fap-web/docs/assessment/domains/career-decision-surface-guard-ledger.md`
- `fap-web/docs/career/career-content-agent-technical-summary-2026-06-25.md`
- `fap-web/docs/claims/career-fit-graph-ai-claim-guards.md`
- `fap-web/docs/seo/personality/big5-authority-v2-runtime-closeout-38-report-2026-07-15.md`

## 11. Repository rule impact

本复盘不改变 runtime 或 content ownership。它澄清并强化现有规则：

- Big Five public editorial content 与 Career content 继续由 fap-api/CMS/public API 权威管理；
- Big Five result/report runtime 继续受 Git-backed snapshot 与 runtime gates 管理；
- frontend 不得新增 CMS-backed fallback；
- career-personality bridge 只能消费已发布 public projection；
- draft import、working revision 和 package PASS 均不代表 public release、indexability 或职业推荐授权。
