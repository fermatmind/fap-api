# MBTI 结果页文案渲染方案

## 0. 目标

本方案定义前端每个 block 的渲染责任，以及它们在后端 canonical schema 中的落点。目标是：

- 前端只负责展示，不再持有 MBTI 文案 source of truth
- simple fallback 从前端移到后端 serializer
- share / seo / og / page metadata 和主结果页同源

## 0.1 PR-1 已完成边界

PR-1 本轮只完成 backend scaffold，没有改任何 `fap-web` 文件，也没有切换任何 live consumer。

已落地的后端骨架：

- `backend/app/Support/Mbti/MbtiPublicTypeIdentity.php`
- `backend/app/Support/Mbti/MbtiCanonicalSectionRegistry.php`
- `backend/app/Support/Mbti/MbtiCanonicalPublicResultSchema.php`
- `backend/app/Contracts/MbtiPublicResultAuthoritySource.php`
- `backend/app/Contracts/MbtiPublicResultPayloadBuilder.php`
- `backend/app/Services/Mbti/MbtiCanonicalPublicResultPayloadBuilder.php`
- `backend/app/Services/Mbti/Adapters/MbtiReportAuthoritySourceAdapter.php`

PR-1 对渲染层真正产生的影响只有两个：

- future frontend block 落点已经在 backend canonical scaffold 里固定
- premium teaser render type 与 `trait_overview` axis 标准化入口已经被后端测试锁住

PR-1 尚未完成的事项：

- 前端结果页接 canonical payload
- share / seo / og / sitemap 切到 canonical projection
- 删除当前 live fallback render path

## 1. 渲染总原则

- 文案内容必须来自后端 canonical payload
- 结果数值必须来自 runtime `result`
- premium 行为必须来自 gatekeeper / cta
- 前端不允许再维护 32 型本地常量
- 若内容缺失，前端只能：
  - 显示 skeleton
  - 隐藏 block
  - 显示后端给出的 `degraded/fallback` 状态
- 前端不能自行补字

PR-1 额外锁定：

- backend canonical payload 顶层 identity 已固定为 `type_code / base_type_code / variant`
- `profile.hero_summary` 已固定为顶部文案唯一 profile 槽位
- 以下 key 已固定进入 `premium_teaser` bucket，而不是普通 section：
  - `growth.motivators`
  - `growth.drainers`
  - `relationships.rel_advantages`
  - `relationships.rel_risks`
- `trait_overview` 已固定保留 axis alias 标准化入口，不允许前端直接消费 `NS / FT`

## 2. 页面 block 到后端字段落点

| 页面区域 | 后端落点 | 前端责任 | 不允许做的事 |
| --- | --- | --- | --- |
| 顶部区 | `profile` + `sections.letters_intro` | 渲染标题、昵称、稀有度、关键词、hero summary、headline | 不能本地拼接 `主人公型 · 温柔引路人` |
| 维度区 | `sections.trait_overview` + runtime `scores_pct/axis_states` | 渲染 5 条进度条、维度说明、summary | 不能本地把 `NS`/`FT` 解释成轴文案 |
| 职业区 | `sections.career` | 渲染概览、优势、短板、职业方向、升级建议 | 不能回退到旧 `career_fit` 简版 bullets |
| 成长区 | `sections.growth` | 渲染概览、强项、短板、premium teaser | 不能把 teaser 当完整内容 |
| 关系区 | `sections.relationships` | 渲染概览、强项、短板、premium teaser | 不能再用基础型单段 `relationships` 旧文案兜底 |
| premium teaser | `sections.growth/relationships.payload_json.premium_teasers[]` + `cta` | 渲染 teaser 卡片与引导按钮 | 不能本地写一套通用付费提示文案 |
| share | canonical derived share projection | 渲染分享卡片 / 文案 / CTA | 不能本地从 `profile.title + summary` 自己拼 |
| SEO | canonical derived metadata + `seo_meta` override | 页面 title/description/canonical | 不能本地单独维护一套 meta 模板 |
| OG | canonical derived metadata + `seo_meta` override | 输出 og title/description/image | 不能和页面主文案脱节 |

## 3. 顶部区渲染责任

## 3.1 后端输入

- `profile.title`
- `profile.hero_kicker`
- `profile.subtitle`
- `profile.excerpt`
- `profile.rarity_text`
- `profile.keywords`
- `sections.letters_intro.payload_json.headline`

## 3.2 前端负责

- 版式与排版
- 稀有度标签视觉
- keywords tag 样式
- head title + hero summary 可读性

## 3.3 前端不负责

- 拼接类型标题
- 解释 `A/T`
- 生成 hero summary

## 4. 维度区渲染责任

## 4.1 后端输入

- `sections.trait_overview.payload_json.dimensions[]`
- runtime `scores_pct`
- runtime `axis_states`

## 4.2 前端负责

- 将 5 个维度按固定顺序渲染：
  - `EI`
  - `SN`
  - `TF`
  - `JP`
  - `AT`
- 将 `scores_pct` 映射为 bar 长度
- 将 `axis_states` 渲染成“明显偏 / 略偏 / 清晰”等视觉状态

## 4.3 前端不负责

- 从 `type_code` 猜维度 summary
- 从 `scores_pct` 猜维度说明
- 把附件的 `NS/FT` 当 runtime 轴名直接消费

## 4.4 维度区 fallback 规则

若 narrative payload 缺失：

- 前端只显示数值条与极性标签
- 不显示本地维度说明长文
- 显示后端下发的 `degraded = true`

## 5. 职业区渲染责任

## 5.1 后端输入

- `sections.career.payload_json.summary`
- `sections.career.payload_json.strengths`
- `sections.career.payload_json.weaknesses`
- `sections.career.payload_json.preferred_roles`
- `sections.career.payload_json.upgrade_suggestions`

## 5.2 前端负责

- 将结构化 blocks 分为：
  - 概览段落
  - 优势项
  - 短板项
  - 职业方向分组
  - 升级建议公式卡

## 5.3 替换 simple fallback 的计划

旧 simple version 常见做法：

- 只显示一句 `career_fit`
- 或只显示推荐职业列表

替换计划：

1. 先接 `sections.career`
2. 若存在 `career_fit` 旧字段，只作为 backend migration fallback
3. 前端不再消费旧 `career_fit` 结构

## 6. 成长区渲染责任

## 6.1 后端输入

- `sections.growth.payload_json.summary`
- `sections.growth.payload_json.strengths`
- `sections.growth.payload_json.weaknesses`
- `sections.growth.payload_json.premium_teasers`
- 可选 `upgrade_suggestions`

## 6.2 前端负责

- 渲染公共可见块：
  - 成长概览
  - 强项
  - 短板
- 渲染付费引导块：
  - motivators teaser
  - drainers teaser

## 6.3 前端不负责

- 自己写“什么在激励着你 / 什么让你疲惫”的默认文案
- 自行决定 teaser 是否显示

## 7. 关系区渲染责任

## 7.1 后端输入

- `sections.relationships.payload_json.summary`
- `sections.relationships.payload_json.strengths`
- `sections.relationships.payload_json.weaknesses`
- `sections.relationships.payload_json.premium_teasers`

## 7.2 前端负责

- 公共区块：
  - 关系概览
  - 关系中的强项
  - 关系中的短板
- premium teaser 区块：
  - 人际关系优势 teaser
  - 人际关系风险 teaser

## 7.3 替换 simple fallback 的计划

当前简单版常见形态：

- 单段 `relationships` rich_text

替换计划：

1. 后端优先返回 `sections.relationships`
2. 前端只认结构化 block
3. 旧 rich_text 只在 migration 期间由 backend serializer 转成结构化 fallback

## 8. premium teaser 渲染责任

## 8.1 后端输入

- `premium_teasers[]`
- `cta.visible`
- `cta.kind`
- `cta.label`
- `cta.path`

## 8.2 前端负责

- teaser 卡片视觉
- CTA 按钮交互
- 锁定态样式

## 8.3 前端不负责

- 决定哪些内容属于 premium
- 生成通用 teaser copy

## 9. SEO / OG / Share 渲染责任

## 9.1 SEO

前端页面 metadata 只能直接消费服务端输出：

- `meta.title`
- `meta.description`
- `meta.canonical`
- `meta.robots`

## 9.2 OG / Twitter

只能消费：

- `meta.og.*`
- `meta.twitter.*`

## 9.3 Share

只能消费 canonical derived share projection：

- `title`
- `subtitle`
- `summary`
- `tagline`
- `share_text` 或等价分享字段

禁止：

- 前端把 `profile.title` 与 `profile.excerpt` 再拼一遍当 share 文案

## 10. simple fallback 替换计划

## Phase 0

- 保留旧页面结构
- 新 canonical payload 先并行输出

## Phase 1

- 前端优先消费 canonical payload
- 所有 fallback 改到后端 serializer

## Phase 2

- 删除前端本地 MBTI copy 常量
- 删除旧 simple block 渲染逻辑

## Phase 3

- share / SEO / OG 全量改为 canonical projection
- 前端只做视觉层

## 11. 前端禁止继续持有的本地文案字段

以下字段不能继续存在于前端本地常量或 utils 中：

- `heroSummary`
- `lettersIntro.headline`
- `lettersIntro.letters[]`
- `overview.paragraphs`
- `traitOverview.intro`
- `traitOverview.dimensions[].summary`
- `traitOverview.dimensions[].description`
- `career.*`
- `growth.*`
- `relationships.*`
- `premium teaser` 所有 teaser 文案
- `share.title`
- `share.subtitle`
- `share.summary`
- SEO title/description 模板
- A/T 与基础型的本地映射词典

## 12. 渲染方案结论

正式上线后，前端的职责应该被压缩为：

- 布局
- 视觉
- 交互
- 状态展示

而不是：

- 管内容
- 猜结构
- 自己补 fallback

只要前端继续持有 32 型 copy，本次 authority 收口就会失效。
