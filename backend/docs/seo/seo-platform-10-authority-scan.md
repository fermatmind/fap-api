# SEO-PLATFORM-10-AUTHORITY-SCAN：CMS 生命周期与 material lastmod 实施合同

状态：`complete_authority_scan_docs_only`

证据截止：`2026-08-25T23:44:01Z`。本轮只读取当前 `fap-api` `origin/main`、Git/GitHub、
schema、authority/publish/review 服务、UI contract 与公开 sitemap；没有修改 CMS、URL Truth、sitemap、
DB、cache、search 或生产数据。

结构化合同：`backend/docs/seo/generated/seo-platform-10-authority-scan.v1.json`。

GitHub 只读检索未发现标题或正文明确绑定 `SEO-PLATFORM-10` 的现行/历史 PR 或 issue。历史 capability
truth 只把 task #10 标为 `not_selected`，表示当时三个 P3 lastmod cluster 未被选中，不证明本能力已实现。

## 唯一当前结论

Article、Career、Personality 都有部分 revision、publish、hash 或 review primitive，但没有统一的
material-change decision、`last_substantive_update_at` authority、URL Truth propagation 或生产 read model。
`SeoContentPublishingUiContract` 明确返回 `production_unproven`，并把 selected revision、saved/review time
与 material lastmod 保持 null。

当前 URL Truth source 对 Article/Career/Personality 多处使用普通 `updated_at`；sitemap 仍直接读取各
authority/projection，而不是只消费 URL Truth，并在若干分支缺日期时回退 `now()`。因此 task #10 整体为
`production_unproven`，material lastmod 维持 `MEASUREMENT_HOLD`。

## 公开生产 baseline observation

`2026-08-25T23:38:57Z` 对公开 sitemap 做一次 bounded、只读、无 raw URL 输出的聚合观察：2643 条均带
lastmod；Article 113 条、100 个不同时间值；Career 2118 条、仅 4 个不同时间值，其中 2092 条共享
`2026-08-25T23:30:06Z`；Personality 357 条、48 个不同时间值。这个结果只证明 sitemap 输出了日期。

Career 大规模同秒聚集与 release 时间接近是 `observed`；其成因与 `CareerDirectoryReadModelBuilder` 把
`provenance_meta.compiled_at` 投影为 `updated_at` 的代码一致，但没有逐 URL authority readback，因果归因
只标记 `inferred`。不产生 P0/P1，也不把“0 个缺 lastmod”误报为“material lastmod 健康”。

## 10-AS-A：authority 与时间字段盘点

| Family | authority chain / 状态 | revision、hash、review | 时间字段 | public / URL Truth / consumer | 当前缺口 |
| --- | --- | --- | --- | --- | --- |
| Article | repository import package 仅是 candidate；CMS `articles` + 指向 `article_translation_revisions` 的 `published_revision_id` 才是公开 authority。translation 状态含 source/machine_draft/human_review/approved/published/stale/archived；lifecycle 含 active/archived/soft_deleted | revision number、source/supersedes、`source_version_hash`、authority source/package hash；`reviewed_by/reviewed_at/approved_at`；`ContentReleaseAudit` 记录 release，revision 没有明确 publisher 字段 | `published_at`、revision `created/updated/reviewed/approved/published_at`；无 authoritative `last_substantive_update_at` / `next_review_due_at` | publish service 原子切换 pointer 后 dispatch URL Truth change；URL Truth 当前写 `articles.updated_at`；sitemap 直接用 `updated_at → published_at → now()`；LLM 与 sitemap eligibility 是派生开关 | Article hash 未完整覆盖 SEO meta/canonical/hreflang/schema；普通 save 会污染 updated_at；review cadence 与 material time 缺失 |
| Career | Career Current repository contract/manifest 是内容 authority；DB/import/compile/runtime projection、directory read model/cache 是确定性派生或发布 authority，不得反向成为正文 authority。CMS job/guide 另有 draft/published 与 lifecycle 状态 | Current aggregate/per-shard hashes；job/guide revisions 只有 revision_no + snapshot/note/creator；trust manifest 有 version、reviewer status/id/reviewed_at；runtime compile 有 compiled_at | job/guide `published_at/updated_at`；trust manifest 有 `last_substantive_update_at/next_review_due_at`，但只覆盖 trust freshness，且多处 fallback 把 asset/job updated_at 当 substantive | detail/list API 和 directory cache 是 public projection；sitemap Career detail 消费 directory `updated_at=compiled_at`，缺值可回退 now；URL Truth Career Guide 使用 `career_guides.updated_at`，尚未统一覆盖 job directory authority | authority 分层最复杂；review、content revision、compile revision 和 lastmod 没有单一 lineage；cache/compile time 可污染 sitemap |
| Personality | MBTI profile/variant CMS 与 Big Five/Enneagram `personality_public_content_assets` 是不同公开 authority；后者由 immutable working revision + human review evidence + promotion 切换到 published asset；repository package 不能绕过 promotion | asset `source_hash/source_package`、published revision pointer、revision no/snapshot/package hash；immutable review evidence 含 reviewer/reviewed_at/decision/evidence hash；asset 有 review_state/last_reviewed_at | asset/profile `published_at/updated_at`；无统一 `last_substantive_update_at/next_review_due_at`。`BigFiveVisibleDateProjector` 能保留 source field provenance，但不是 material decision | public API/render 读 published asset/profile；URL Truth 与 sitemap 主要用 asset/profile/variant updated_at，缺值回退 published_at/now；sitemap 不是 authority | 现有 content/source hash 很强，但未成为跨 MBTI/Big Five/Enneagram 的 material-lastmod contract；profile 与 asset 两套链路未统一 |

字段语义必须分离：`published_at` 是公开生效时间；`reviewed_at` 是人类审核证据时间；`updated_at` 是行写入
时间；`last_substantive_update_at` 才是经 material fingerprint 判定的内容/搜索表面变化时间；
`next_review_due_at` 是 review policy 结果。任何一项都不能自动替代另一项。

现有 `ContentLifecycleService` 可按 14 天 `updated_at` 查找 stale draft，并有 archive/downrank/soft-delete
写能力。它仅适合复用 tenant scope、权限、audit 和 lifecycle action 语义；不能作为公开内容衰减判定器，
也不能由 #10 Agent 自动调用。UI 当前只列 Article/Career Guide/Career Job，Personality 仍缺统一入口。

## 10-AS-B：material change 与 fingerprint

六类 material change：

1. 正文事实、结论、边界或用户可见解释变化；
2. 新增、删除或实质重写公开模块；
3. claim/source/evidence 变化；
4. title、description、canonical、hreflang、indexability、robots 或 eligible JSON-LD 变化；
5. locale counterpart 的正文、search surface 或 authority linkage 实质变化；
6. authority revision、canonical public structure、公开 internal-link graph 或 publish/unpublish/retire 变化。

八类默认 non-material：纯格式/空白、无语义排序、后台备注、非公开内部字段、view/统计计数、cache warm、
deploy 时间、相同内容的 projection rebuild，以及不改变可见内容/搜索表面的样式调整。任一 change class 若
无法判定，必须 `unknown + MEASUREMENT_HOLD`，不能写当前时间。

统一 fingerprint 为 `seo.material_fingerprint.v1`：

`sha256(canonical_json({schema_version,family,locale,public_identity,authority_revision_kind,visible_content,
claims_and_sources,search_surface,locale_linkage,public_structure}))`。

canonical JSON 使用 UTF-8、对象 key 字典序、数组按业务语义排序、Unicode/slash 不转义、无额外空白。
Article 输入 published translation revision + SEO meta；Career 输入 Current 固定模块/locale + approved
search/review contract；Personality 输入 published profile/variant 或 published public asset snapshot + review-bound
search surface。排除 DB ids、actor/user、review/publish/updated timestamps、revision counter、cache/deploy/compile
time、view/analytics、private notes、runtime envelope 和随机排序。family/locale/identity/schema version 必须入 hash，
避免跨 authority 碰撞。

重复发布同一 fingerprint 必须 preserve 原 material time；旧 hash 缺失或 algorithm version 不明时不可猜测，
进入 hold。现有 Article `source_version_hash`、Career aggregate/shard hashes、Personality `source_hash` 可作为输入
或 lineage evidence，但不能原样冒充统一 fingerprint，因为覆盖面与 exclusion 规则不同。

## 10-AS-C：唯一 material lastmod 传播链

唯一链路为：

`内容 authority → fingerprint/decision → publish revision → URL Truth lastmod_at/source → sitemap/llms 等只读消费者 → public readback`。

URL Truth 只接受已发布 authority 的 `{family,locale,public_identity,authority_revision,material_fingerprint,
material_changed_at,decision_code,evidence_ref}`。`lastmod_source` 必须可追溯，例如
`material_fingerprint.v1:article_translation_revision`，不能写 `updated_at` 的模糊别名。sitemap、llms 与其他
consumer 只能读取 URL Truth 的可信值；不得直接查询 model timestamps、用 cache/compile/deploy time 补齐，
也不得反向创建 authority。

| 事件 | material time 规则 | failure / recovery |
| --- | --- | --- |
| initial publish | 已批准 fingerprint 首次公开生效时间 | 无 publish/readback receipt 则 hold，不写 lastmod |
| republish | fingerprint 改变才使用本次真实公开生效时间；相同则保留 | pointer/readback 不一致开一个 revision cluster |
| locale update | 每 locale 独立 fingerprint/time；counterpart linkage 变化只更新受影响 locale | counterpart 缺 authority 时 fail closed |
| canonical/indexability/structured surface | 搜索表面 hash 改变即 material | URL Truth 先验证新 canonical/eligibility，再供 consumer 读取 |
| unpublish/retire | 删除/410/noindex authority event，不伪造 active lastmod | sitemap/llms 移除；旧可信值保留审计，不继续公开 |
| rollback | public fingerprint 确实改变时使用 rollback 实际生效时间并引用目标历史 revision；无字节/表面变化则保留 | 必须 public readback 对齐，不能用 rollback job completion time |
| idempotent rerun | fingerprint 相同，pointer/time 不变 | 重跑 receipt 只记 operation，不改 lastmod |
| projection/cache rebuild | 永不改变 material time | consumer failure 保留 LKG，不以 now 覆盖 |

传播任一步缺 evidence、consumer 缺值、revision/readback 不一致或 source stale 时，保持旧可信 URL Truth 值并
产生一个 cluster，状态 `MEASUREMENT_HOLD`。恢复要求 authority/fingerprint/URL Truth/sitemap/public readback
连续两次对齐；只有具备 material evidence 才关闭，不能因 cache 重建成功自动关闭。

## 10-AS-D：审核、衰减、刷新与退役候选

review cadence 必须由版本化 `family × locale × claim_risk` policy 决定。Article 可复用 revision human-review
evidence；Career 可复用 trust manifest cadence，但需绑定实际 Current revision；Personality 可复用 immutable
review evidence与 `last_reviewed_at`。三者都需新增统一 `last_substantive_update_at`、policy version、
`next_review_due_at` 与 overdue reason。overdue 只创建 review candidate，不自动 noindex/retire。

首个内容衰减候选规则（`proposed`，未批准为生产策略）：同一 public identity/family/locale 最近完整 28 天与
此前可比 28 天相比，impressions 下降至少 30%，两个窗口各至少 500 impressions，GSC 延迟不超过 3 天，
页面年龄至少 56 天，最近 28 天无 material change，且无 runtime/canonical/noindex/seasonality incident。
clicks、CTR 和 position 只作解释/guardrail，不把 position 均值直接当因果。敏感性必须同时报告最低样本
200/500/1000 与下降 20%/30%/40% 的候选量；在选择 family-specific 阈值前保持 hold。

新页冷启动、强季节性、品牌/算法外部事件、低样本、locale 不可比、GSC stale、authority/readback mismatch
均排除或 hold。候选类别为 refresh、merge/consolidation、retire/noindex；每个候选必须包含 evidence window、
material revision、runtime health、canonical/indexability、替代/redirect 方案、恢复条件和人工决定。

Agent 最高 L1：只读分析、生成候选和 draft rationale。refresh publish、merge、redirect、noindex、retire、删除
均需人工审核和未来 Policy Gateway；删除永不自动执行。否决/恢复后记录原因，两个完整窗口恢复且 review
完成方可关闭。

## 实施交接

最小顺序：

1. 新增版本化 fingerprint library、family adapters 与 golden vectors；
2. 在现有 publish transaction 内写 append-only material decision/revision lineage，保留原时间的幂等语义；
3. 让 URL Truth 只接收 material authority event，并迁移现有 `updated_at` source 为 unavailable/verified 值；
4. 让 sitemap/llms 改为只读 URL Truth，删除 `now()` fallback；
5. 建统一 read model/API，最后绑定 `SeoContentPublishingUiContract`；
6. 增加 review/decay candidate evaluator，保持 L1 与 `search_submission_allowed=false`。

聚焦测试应覆盖三 family golden hash、material/non-material matrix、locale、publish/republish/rollback/idempotency、
unpublish/retire、旧 hash hold、URL Truth source lineage、sitemap/llms 禁止直接 timestamp/now fallback、consumer
LKG、review overdue、28d decay exclusions、Agent 权限和 UI null/zero。上线验收需 exact-SHA CI、staging migration
compatibility、bounded backfill dry-run、public readback、sitemap/llms parity 与 rollback；本 scan 未实施 runtime。

## Deferred / 真实风险

- 当前 production sitemap 日期齐全但不证明 material truth；Career 同秒聚集需后续 authority-bound readback。
- Career authority/review/compile/cache 多层 lineage 尚未统一，是实现风险最高的 family。
- 现有 ContentLifecycleService 有真实写能力，后续不得把 decay candidate 直接接入其 bulk action。
- `read_only_gsc=true`；`search_submission_allowed=false`。
