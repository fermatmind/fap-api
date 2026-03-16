# MBTI 结果页文案 Canonical Schema

## 0. 目标

本文件定义一份正式可实施的 MBTI 结果页 canonical schema，用于把附件 `/Users/rainie/Desktop/微信小程序结果页文案.txt` 中的前端对象结构，转成当前后端链路可承载、可批量导入、可测试、可验收的后端读模型。

设计原则固定如下：

- 只保留一个公共文案 authority，不能继续让 `report`、`personality`、`share`、前端本地常量各持一份
- `type_code` 必须以 `ENFJ-T` 这类 32 型完整码为主身份
- 数值维度继续由 `results` runtime 提供，叙事文案由 canonical content 提供
- `growth` / `relationships` 中当前只有 teaser 的模块继续走 premium teaser，不伪造完整版
- `seo/share/og` 尽量做 derived projection，只在确有运营覆盖需求时写 override

## 0.1 PR-1 已落地骨架

本轮已经实际落地、但尚未接入 live controller 的代码骨架如下：

- `backend/app/Support/Mbti/MbtiPublicTypeIdentity.php`
- `backend/app/Support/Mbti/MbtiCanonicalSectionRegistry.php`
- `backend/app/Support/Mbti/MbtiCanonicalPublicResultSchema.php`
- `backend/app/Contracts/MbtiPublicResultAuthoritySource.php`
- `backend/app/Contracts/MbtiPublicResultPayloadBuilder.php`
- `backend/app/Services/Mbti/MbtiCanonicalPublicResultPayloadBuilder.php`
- `backend/app/Services/Mbti/Adapters/MbtiReportAuthoritySourceAdapter.php`

PR-1 当前已锁定的事实：

- `type_code` 必须是 `ENFJ-T` 这类 `5` 位 runtime identity
- `base_type_code` 与 `variant` 只允许派生，不允许反向覆盖 `type_code`
- canonical payload scaffold 当前固定输出：
  - 顶层 `type_code`
  - 顶层 `base_type_code`
  - 顶层 `variant`
  - `profile.hero_summary`
  - `sections`
  - `premium_teaser`
  - `seo_meta`
  - `_meta`
- `trait_overview` 已有 axis 标准化入口：
  - `NS -> SN`
  - `FT -> TF`
- premium teaser key 已固定到 `premium_teaser` render type，PR-1 测试禁止伪装成 full section
- 本轮没有切换任何 public route，也没有开始导入 `32` 型正文

## 1. 附件实际结构结论

附件并不是纯文案 TXT，而是一个 JS-like 数据文件：

- 共 32 个类型对象
- 另有一个聚合对象 `MBTI_PROFILES`
- 每个类型对象固定包含 6 个一级模块：
  - `lettersIntro`
  - `overview`
  - `traitOverview`
  - `career`
  - `growth`
  - `relationships`
- 顶部还有：
  - `code`
  - `type`
  - `name`
  - `nickname`
  - `rarity`
  - `keywords`
  - `heroSummary`

已确认的结构差异：

- 附件里的维度 ID 使用 `NS` / `FT`
- 后端 runtime 维度规范使用 `SN` / `TF`
- canonical schema 必须统一到后端规范：`EI / SN / TF / JP / AT`

## 2. Canonical 实体拆分

正式落地时，公共结果页 authority 拆成四层：

1. `profile`
2. `sections`
3. `seo_meta`
4. `derived projections`

其中：

- `profile`：承接类型身份、头部展示、公共摘要
- `sections`：承接结构化结果页正文
- `seo_meta`：只承接 override，不承接正文正文块
- `derived projections`：运行时投影，不建议直接持久化
  - `share`
  - `og`
  - `page metadata`

## 3. 正式 canonical object 形态

PR-1 当前真实落地的是“scaffold 版 canonical payload”，比最终导入完成态更窄，但顶层 identity 和 bucket 已锁住：

```yaml
type_code: ENFJ-T
base_type_code: ENFJ
variant: T

profile:
  slug: enfj-t
  slug_base: enfj
  locale: zh-CN
  hero_summary: null

sections:
  letters_intro:
    section_key: letters_intro
    render_variant: rich_text
    title: null
    body: null
    payload: null
  trait_overview:
    section_key: trait_overview
    render_variant: trait_dimension_grid
    payload:
      summary: null
      dimensions: []
      axis_aliases:
        NS: SN
        FT: TF
  career.summary:
    section_key: career.summary
    render_variant: rich_text
    body: null

premium_teaser:
  growth.motivators:
    section_key: growth.motivators
    render_variant: premium_teaser
    teaser: null
    is_premium_teaser: true
  relationships.rel_risks:
    section_key: relationships.rel_risks
    render_variant: premium_teaser
    teaser: null
    is_premium_teaser: true

seo_meta:
  seo_title: null
  seo_description: null
  og_title: null
  og_description: null
  og_image_url: null
  twitter_title: null
  twitter_description: null
  twitter_image_url: null
  robots: null
  jsonld_overrides_json: null

derived:
  share: generated
  page_metadata: generated
  og: generated

_meta:
  authority_source: report.v0_3.pilot
  schema_version: mbti-public-canonical-pr1
  scaffold: true
```

## 4. 哪些字段进入 `profile`

### 4.1 `profile` 必须承接的字段

| Canonical 字段 | 来源 | 当前是否已有位置 | 处理方式 |
| --- | --- | --- | --- |
| `type_code` | 附件 `code` / runtime result | 已有 | 直接用 |
| `base_type_code` | 附件 `type` / `type_code` 派生 | 无 | 扩 schema |
| `variant` | `type_code` 后缀 | 无 | 扩 schema |
| `slug` | 由 `type_code` 生成 | 已有但当前是基础型 | 改为 variant-aware |
| `slug_base` | `base_type_code` 生成 | 无 | 扩 schema 或 alias 规则 |
| `locale` | 导入上下文 | 已有 | 直接用 |
| `title` | 附件 `name` | 已有 | 直接映射到 `title` |
| `hero_kicker` | 附件 `nickname` | 已有 | 复用现有字段 |
| `subtitle` | 由 `lettersIntro.headline` 压缩版或规范化摘要生成 | 已有 | 走导入规则，不直接生吞 headline 原文 |
| `excerpt` | 附件 `heroSummary` | 已有 | 直接映射到 `excerpt` |
| `tagline` | 优先现有 `report_identity_cards` / `type_profiles`；附件无独立字段 | 无 | 扩 schema |
| `rarity_text` | 附件 `rarity` | 无 | 扩 schema |
| `keywords_json` | 附件 `keywords[]` | 无 | 扩 schema |
| `schema_version` | 导入流程 | 已有 | 升为 `mbti_result_v2` |

### 4.2 `profile` 不建议直接承接的字段

以下字段不应塞进 `profile`：

- `lettersIntro.letters[]`
- `overview.paragraphs[]`
- `traitOverview.dimensions[]`
- `career` / `growth` / `relationships` 的正文块

原因：

- 这些字段是结构化正文，不是 profile 头部字段
- 塞进 profile 会让 SEO/share/public API 变成“单表吃一切”的脆弱结构

## 5. 哪些字段进入 `sections`

建议 section 粒度固定为 6 个，和附件的一致：

- `letters_intro`
- `overview`
- `trait_overview`
- `career`
- `growth`
- `relationships`

不建议拆成 20+ 个 section row。正文块层级保留在 `payload_json` 内部。

### 5.1 `letters_intro`

```yaml
section_key: letters_intro
render_variant: letters_intro_v2
payload_json:
  headline: 外向 · 直觉 · 情感 · 规划 · 动荡型｜...
  items:
    - order: 1
      letter: E
      title: 外向（E）
      description: ...
    - order: 2
      letter: N
      title: 直觉（N）
      description: ...
    - order: 3
      letter: F
      title: 情感（F）
      description: ...
    - order: 4
      letter: J
      title: 规划（J）
      description: ...
    - order: 5
      letter: T
      title: 动荡型（-T）
      description: ...
```

### 5.2 `overview`

```yaml
section_key: overview
render_variant: longform_v2
payload_json:
  display_title: 人格概要
  full_title: 你是怎样的 ENFJ-T？
  paragraphs:
    - ...
    - ...
    - ...
```

### 5.3 `trait_overview`

`trait_overview` 的 canonical payload 必须显式区分“文案层”和“数值层”：

```yaml
section_key: trait_overview
render_variant: trait_overview_v2
payload_json:
  intro: 建议与小程序中 5 条维度进度条搭配展示...
  dimensions:
    - axis_code: EI
      display_name: 外向倾向
      left_pole:
        letter: E
        label: 外向
      right_pole:
        letter: I
        label: 内向
      summary: 略偏外向
      description: ...
    - axis_code: SN
      source_axis_code: NS
      display_name: 心智模式
      left_pole:
        letter: N
        label: 天马行空
      right_pole:
        letter: S
        label: 求真务实
      summary: 偏天马行空
      description: ...
    - axis_code: TF
      source_axis_code: FT
      display_name: 情绪天性
      left_pole:
        letter: F
        label: 情感细腻
      right_pole:
        letter: T
        label: 理性思考
      summary: 偏情感细腻
      description: ...
    - axis_code: JP
      ...
    - axis_code: AT
      ...
```

强制规则：

- canonical `axis_code` 只能是 `EI/SN/TF/JP/AT`
- `source_axis_code` 可选，仅在导入层保留附件原始 `NS/FT`
- 前端禁止再根据 `NS/FT` 做本地猜测

### 5.4 `career`

```yaml
section_key: career
render_variant: structured_blocks_v2
payload_json:
  summary:
    title: 职业概览
    paragraphs: [...]
  strengths:
    title: 你的职场优势
    items:
      - title: ...
        description: ...
  weaknesses:
    title: 你的职场短板
    items:
      - title: ...
        description: ...
  preferred_roles:
    title: 你可能会喜欢的职业方向
    intro: ...
    groups:
      - group_title: ...
        description: ...
        examples: [...]
    outro: ...
  upgrade_suggestions:
    title: 职场升级建议
    paragraphs: [...]
    bullets:
      - label: ...
        content: ...
```

### 5.5 `growth`

```yaml
section_key: growth
render_variant: structured_blocks_v2
payload_json:
  summary:
    title: 成长概览
    paragraphs: [...]
  strengths:
    title: 你的强项
    items: [...]
  weaknesses:
    title: 你的短板
    items: [...]
  premium_teasers:
    - premium_key: motivators
      title: 什么在激励着你
      teaser: ...
      access_level: premium_teaser
    - premium_key: drainers
      title: 什么让你感到疲惫
      teaser: ...
      access_level: premium_teaser
  upgrade_suggestions:
    optional: true
    title: 个人成长小公式
    paragraphs: [...]
    bullets: [...]
```

### 5.6 `relationships`

```yaml
section_key: relationships
render_variant: structured_blocks_v2
payload_json:
  summary:
    title: 关系概览
    paragraphs: [...]
  strengths:
    title: 关系中的强项
    items: [...]
  weaknesses:
    title: 关系中的短板
    items: [...]
  premium_teasers:
    - premium_key: rel_advantages
      title: 你的人际关系优势
      teaser: ...
      access_level: premium_teaser
    - premium_key: rel_risks
      title: 人际关系风险
      teaser: ...
      access_level: premium_teaser
```

## 6. 哪些字段进入 `seo_meta`

`seo_meta` 只承接 override，不承接正文结构本体。

### 6.1 可进入 `seo_meta` 的字段

- `seo_title`
- `seo_description`
- `canonical_url`
- `og_title`
- `og_description`
- `og_image_url`
- `twitter_title`
- `twitter_description`
- `twitter_image_url`
- `robots`
- `jsonld_overrides_json`

### 6.2 不应直接从附件导入到 `seo_meta` 的字段

- `heroSummary`
- `overview.paragraphs`
- `traitOverview.dimensions[].description`
- `career/growth/relationships` 正文

处理方式：

- Phase 1：先按 canonical rules 自动生成
- Phase 2：运营确需覆盖时再写 `seo_meta`

建议默认生成规则：

- `seo_title`：`{type_code} {title}：特质、职业、成长与关系 | FermatMind`
- `seo_description`：优先 `excerpt`，长度超限时截断
- `og_title`：默认同 `seo_title`
- `og_description`：默认同 `seo_description`

## 7. 哪些字段继续走 premium teaser

附件里目前明确只有以下 4 个模块应继续走 premium teaser：

- `growth.motivators`
- `growth.drainers`
- `relationships.relAdvantages`
- `relationships.relRisks`

处理规则：

- 持久化位置：仍在所属 section 的 `payload_json.premium_teasers[]`
- 展示位置：结果页 public surface 只展示 teaser，不展示 full premium body
- 行为链接：仍接 `ReportGatekeeper` / `cta`

明确不做的事：

- 不从 teaser 伪造出完整 premium 长文
- 不把 teaser 单独拷成前端本地付费弹窗文案

## 8. 哪些字段不能进入当前链路，必须扩 schema

以下字段或能力，不能靠当前 schema 原样承接，必须扩 schema 或扩约束：

| 项目 | 原因 | 建议 |
| --- | --- | --- |
| `base_type_code` | 当前无字段 | `personality_profiles` 新增字段 |
| `variant` | 当前无字段 | `personality_profiles` 新增字段 |
| 32 型 `type_code` 约束 | 当前模型/normalizer 只接受 16 型 | 扩 `TYPE_CODES` 与 baseline 校验 |
| `tagline` | 当前 `personality_profiles` 无字段 | 顶层新增字段 |
| `rarity_text` | 当前无字段 | 顶层新增字段 |
| `keywords_json` | 当前无字段 | 顶层新增 JSON 字段 |
| variant-aware `slug` 规则 | 当前 personality slug 只按基础型假设 | 增加 variant slug / alias 规则 |
| `letters_intro` / `trait_overview` / `career` / `growth` / `relationships` 新 section keys | 当前 `SECTION_KEYS` 无法承载 | 扩 `SECTION_KEYS` |
| 新 `render_variant` | 当前只有 `rich_text/bullets/cards/...` | 新增 `letters_intro_v2/trait_overview_v2/structured_blocks_v2` |
| share 自定义 authoring | 当前无 share_meta 存储 | Phase 1 不扩；沿用 derived projection。若要单独 authoring，再扩 schema |

## 9. 哪些字段不应直接进入当前链路，而应保持 derived

这些字段建议继续走 derived projection，而不是直接存库：

- `share.title`
- `share.subtitle`
- `share.summary`
- `share.share_text`
- `page metadata`
- `og projection`

理由：

- 附件没有提供专门的 share / SEO authoring
- 当前 backend 已有 `report_identity_cards.share_text` 可兜住 share 文案
- 先用 derived projection，能减少 schema 爆炸

## 10. 标题规范化规则

附件中存在两类不适合直接存库的标题：

- 带顺序号的标题，例如 `1. 人格概要｜你是怎样的 ENFJ-T？`
- 语义不完整的块标题，例如：
  - ` 的短板`
  - ` 系中的强项`

正式 canonical 规则：

- 顺序号不落库，用 `sort_order` 表达顺序
- section/block 标题优先使用 canonical label dictionary
- 附件标题只作为原始素材参考，不作为唯一 truth

canonical label dictionary 示例：

- `career.advantages.title` => `你的职场优势`
- `growth.weaknesses.title` => `你的短板`
- `relationships.strengths.title` => `关系中的强项`
- `relationships.weaknesses.title` => `关系中的短板`

## 11. Pilot Mapping

## 11.1 `ENFJ-T` 完整样板

下面是“后端可承载结构示意”，不是最终业务代码。

```yaml
profile:
  type_code: ENFJ-T
  base_type_code: ENFJ
  variant: T
  slug: enfj-t
  slug_base: enfj
  locale: zh-CN
  title: 主人公型
  hero_kicker: 温柔引路人
  subtitle: 理想主义与现实执行力并存的温柔引路人
  excerpt: 你像一盏不刺眼的灯：愿意走到人群中央，又始终在照亮别人...
  tagline:
    source: report_identity_cards/type_profiles existing authority
  rarity_text: 约 2–5%
  keywords:
    - 共情
    - 愿景感
    - 协调者
    - 服务型领导
    - 价值驱动
    - 自我反思
  schema_version: mbti_result_v2

sections:
  - section_key: letters_intro
    payload_json:
      headline: 外向 · 直觉 · 情感 · 规划 · 动荡型｜理想主义与现实执行力并存的温柔引路人
      items:
        - {order: 1, letter: E, title: 外向（E）, description: ...}
        - {order: 2, letter: N, title: 直觉（N）, description: ...}
        - {order: 3, letter: F, title: 情感（F）, description: ...}
        - {order: 4, letter: J, title: 规划（J）, description: ...}
        - {order: 5, letter: T, title: 动荡型（-T）, description: ...}
  - section_key: overview
    payload_json:
      display_title: 人格概要
      full_title: 你是怎样的 ENFJ-T？
      paragraphs: [...]
  - section_key: trait_overview
    payload_json:
      intro: 建议与小程序中 5 条维度进度条搭配展示...
      dimensions:
        - {axis_code: EI, source_axis_code: EI, display_name: 外向倾向, left_pole: {letter: E, label: 外向}, right_pole: {letter: I, label: 内向}, summary: 略偏外向, description: ...}
        - {axis_code: SN, source_axis_code: NS, display_name: 心智模式, left_pole: {letter: N, label: 天马行空}, right_pole: {letter: S, label: 求真务实}, summary: 偏天马行空, description: ...}
        - {axis_code: TF, source_axis_code: FT, display_name: 情绪天性, left_pole: {letter: F, label: 情感细腻}, right_pole: {letter: T, label: 理性思考}, summary: 偏情感细腻, description: ...}
        - {axis_code: JP, source_axis_code: JP, ...}
        - {axis_code: AT, source_axis_code: AT, ...}
  - section_key: career
    payload_json:
      summary: {...}
      strengths: {...}
      weaknesses: {...}
      preferred_roles: {...}
      upgrade_suggestions: {...}
  - section_key: growth
    payload_json:
      summary: {...}
      strengths: {...}
      weaknesses: {...}
      premium_teasers:
        - {premium_key: motivators, title: 什么在激励着你, teaser: ...}
        - {premium_key: drainers, title: 什么让你感到疲惫, teaser: ...}
  - section_key: relationships
    payload_json:
      summary: {...}
      strengths: {...}
      weaknesses: {...}
      premium_teasers:
        - {premium_key: rel_advantages, title: 你的人际关系优势, teaser: ...}
        - {premium_key: rel_risks, title: 人际关系风险, teaser: ...}

seo_meta:
  seo_title: null
  seo_description: null
  og_title: null
  og_description: null
```

### `ENFJ-T` 中 100% 可落的字段

- `code/type/name/nickname/rarity/keywords/heroSummary`
- `lettersIntro.headline`
- `lettersIntro.letters[]`
- `overview.paragraphs[]`
- `traitOverview.intro`
- `traitOverview.dimensions[].summary/description`
- `career.summary/advantages/weaknesses/preferredRoles/upgradeSuggestions`
- `growth.summary/strengths/weaknesses`
- `growth.motivators.teaser`
- `growth.drainers.teaser`
- `relationships.summary/strengths/weaknesses`
- `relationships.relAdvantages.teaser`
- `relationships.relRisks.teaser`

### `ENFJ-T` 仍需扩 schema 的字段

- `base_type_code`
- `variant`
- `tagline`
- `keywords_json`
- `rarity_text`
- `slug_base`
- 新 section keys / render variants

## 11.2 `INFP-T` 对照样板

`INFP-T` 与 `ENFJ-T` 结构完全同型，只是值不同。关键对照点如下：

```yaml
profile:
  type_code: INFP-T
  base_type_code: INFP
  variant: T
  title: 调停者型
  hero_kicker: 温柔筑梦者
  excerpt: 你像一团安静却倔强的火焰...
sections:
  letters_intro:
    items:
      - I
      - N
      - F
      - P
      - T
  trait_overview:
    dimensions:
      - axis_code: EI
        summary: 明显偏内向
      - axis_code: SN
        source_axis_code: NS
        summary: 偏天马行空
      - axis_code: TF
        source_axis_code: FT
        summary: 明显偏情感细腻
      - axis_code: JP
        summary: 略偏随机应变
      - axis_code: AT
        summary: 略偏情绪易波动
  growth:
    premium_teasers:
      - motivators
      - drainers
  relationships:
    premium_teasers:
      - rel_advantages
      - rel_risks
```

### `INFP-T` 中 100% 可落的字段

- 与 `ENFJ-T` 同结构，全量可落到 canonical content

### `INFP-T` 仍需扩 schema 的字段

- 与 `ENFJ-T` 同一批扩 schema 项

## 12. Canonical Schema 结论

附件的主体内容并不需要拆回前端常量。真正要做的是：

- 以 `profile + 6 sections + seo override + derived share/meta` 的形态入后端
- 明确 `growth/relationships` 的 4 个 teaser 继续走 premium teaser
- 用 canonical axis code 统一掉 `NS/FT`
- 对标题和顺序做规范化，而不是把附件文本原样视为数据库 schema
