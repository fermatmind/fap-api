# MBTI Desktop Clone Content Owner (32 FullCode)

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

## 4. Storage Owner 负责字段
`content_json` 仅承载 desktop clone authored 内容，覆盖：

- `hero.summary`
- `intro.paragraphs`
- `traits.summaryPane.*`
- `traits.body`
- `chapters.career`
- `chapters.growth`
- `chapters.relationships`
- `finalOffer`

`asset_slots_json` 为后续真实资产 owner 预留结构：

- `slotId`
- `label`
- `aspectRatio`
- `status`
- `assetRef`（nullable）
- `alt`（nullable）
- `meta`（nullable）

## 5. 继续属于 Runtime 的字段
以下仍由结果 runtime/projection 提供，不进入 clone content owner：

- fullCode/baseCode runtime 真值
- display title
- bars/dimensions
- tools/actions
- unlock/purchase handlers
- runtime price
- attempt/user-scoped 状态

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

## 7. Seed 覆盖范围
当前基线导入覆盖：

- 32 个 fullCode（A/T）
- `zh-CN`
- `template_key = mbti_desktop_clone_v1`

导入来源：`fap-web` 当前已 authored 32 型 desktop clone 内容，转存为 `fap-api` 仓内 baseline（JSON）。

## 8. 当前未覆盖
- `en` locale backfill
- 真实 asset refs（对象存储接入）

## 9. 下一步 PR
下一张卡在 `fap-web` 执行 consumer 切换：

- resolver 切到上述 published read contract
- 前端本地 registry 从 runtime owner 降级为迁移遗留/参考源
