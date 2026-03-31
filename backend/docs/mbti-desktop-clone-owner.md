# MBTI Desktop Clone Content + Asset Slot Owner (32 FullCode)

## 1. Owner 挂载位置
MBTI Desktop clone 正文 owner 挂在现有 personality owner 域内：

- `personality_profiles`（base 16 型 profile owner）
- `personality_profile_variants`（32 fullCode A/T variant owner）
- `personality_profile_variant_clone_contents`（本 PR 新增 clone-specific owner）

关联关系：

- `personality_profile_variants.id` -> `personality_profile_variant_clone_contents.personality_profile_variant_id`
- 每个 variant 在同一个 `template_key` 下最多一条 current owner（唯一约束）。

## 2. 为什么不是第二个平台
本实现复用了既有 personality/career owner 的发布语义、Ops 管理入口和公开读取路径风格，
仅在 `PersonalityProfileVariant` 下新增 clone-specific 内容表，不新建独立 CMS 平台，避免 owner 分叉。

## 3. Clone Content 表结构
表名：`personality_profile_variant_clone_contents`

核心字段：

- `id`
- `personality_profile_variant_id`
- `template_key`（当前固定 `mbti_desktop_clone_v1`）
- `status`（`draft` / `published`）
- `schema_version`
- `content_json`
- `asset_slots_json`
- `meta_json`
- `published_at`
- `created_at`
- `updated_at`

约束：

- unique(`personality_profile_variant_id`, `template_key`)

## 4. Storage Owner 负责字段（正文 vs 资产）
`content_json` 仅承载 desktop clone authored 内容，覆盖：

- `hero.summary`
- `intro.paragraphs`
- `letters_intro.headline`
- `letters_intro.letters[]`
- `overview.title`
- `overview.paragraphs[]`
- `traits.summaryPane.*`
- `traits.body`
- `chapters.career`
- `chapters.growth`
- `chapters.relationships`
- `finalOffer`

当前 P0 主链已 authoritative 挂载（本 PR）：

- `letters_intro`
- `overview`
- `chapters.career.strengths`
- `chapters.career.weaknesses`
- `chapters.growth.strengths`
- `chapters.growth.weaknesses`
- `chapters.relationships.strengths`
- `chapters.relationships.weaknesses`
- `chapters.career.matched_jobs`
- `chapters.career.matched_guides`

当前 P1 深层模块已 authoritative 挂载：

- `chapters.career.career_ideas`
- `chapters.career.work_styles`
- `chapters.growth.what_energizes`
- `chapters.growth.what_drains`
- `chapters.relationships.superpowers`
- `chapters.relationships.pitfalls`

`asset_slots_json` 为 desktop clone 资产引用 owner（挂在 clone content owner 内，不另起平台）：

- `slot_id`
- `label`
- `aspect_ratio`
- `status`
- `asset_ref`（nullable）
- `alt`（nullable）
- `meta`（nullable）

`status` 语义：

- `placeholder`：槽位存在，但还未绑定真实资产 ref（允许 `asset_ref = null`）
- `ready`：槽位已可用，必须绑定合法 `asset_ref`
- `disabled`：槽位暂不对外投放（可保留历史 ref，也可为 null）

`asset_ref` 最小结构：

- `provider`（`oss` / `cdn` / `internal` / `placeholder`）
- `path` 或 `url`（`ready` 至少一个非空）
- `version`（nullable）
- `checksum`（nullable）

固定 slot_id（v1 白名单）：

- `hero-illustration`
- `traits-illustration`
- `traits-summary-illustration`
- `career-illustration`
- `growth-illustration`
- `relationships-illustration`
- `final-offer-illustration`

## 5. 继续属于 Runtime 的字段
以下仍由结果 runtime/projection 提供，不进入 clone content owner：

- fullCode/baseCode runtime 真值
- display title
- bars/dimensions
- tools/actions
- unlock/purchase handlers
- runtime price
- attempt/user-scoped 状态
- runtime personalization（`selection_fingerprint` / `evidence` / `adaptive` / `memory`）

## 6. Public Read Contract
新增 public endpoint：

- `GET /api/v0.5/personality/{type}/desktop-clone?locale={locale}`

行为：

- `{type}` 必须为 fullCode（如 `infj-a` / `entj-t`）
- 仅返回 `published`
- 未命中返回 `NOT_FOUND`
- 不做 baseCode fallback

响应关键字段：

- `template_key`
- `schema_version`
- `full_code`
- `base_code`
- `locale`
- `content`
- `asset_slots`
- `_meta`（authority_source / route_mode / route type 等）

`asset_slots` 发布契约：

- published only
- fullCode exact
- no baseCode fallback
- placeholder slot 也必须完整返回，不吞字段
- ready slot 返回可消费 `asset_ref`

## 7. Seed 覆盖范围
当前基线导入覆盖：

- 32 个 fullCode（A/T）
- `zh-CN`
- `template_key = mbti_desktop_clone_v1`
- P0 + P1 模块完整性门禁（缺 fullCode / 缺关键模块直接失败）

导入来源：`fap-web` 当前已 authored 32 型 desktop clone 内容，转存为 `fap-api` 仓内 baseline（JSON）。

## 8. 当前未覆盖
- `en` locale backfill
- 真实 AI 资产生成/上传与批处理
- runtime personalization（`selection_fingerprint` / `evidence` / `adaptive` / `memory`）

## 9. 下一步 PR 顺序
1. `fap-web`：desktop clone P1 深层模块消费接入（`career_ideas/work_styles`、`what_energizes/what_drains`、`superpowers/pitfalls`）
2. `fap-api` 或 runtime 管线：同型内 runtime personalization 挂载（不改静态 owner）
3. `fap-api` 或离线管线：AI 资产生成 + asset_ref 回填（仅数据替换，不改 schema）
