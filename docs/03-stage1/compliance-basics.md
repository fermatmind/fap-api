> Status: Active
> Owner: liufuwei
> Last Updated: 2025-12-16
> Version: Compliance Basics v0.3 (Stage 1 / v0.3-A)
> Related Docs:
> - docs/README.md
> - docs/03-stage1/README.md
> - docs/03-stage1/fap-v0.3-glossary.md
> - docs/03-stage1/api-v0.3-spec.md
> - docs/03-stage1/copywriting-no-go-list.md
> - docs/04-stage2/compliance-stage2-user-rights.md

# 合规最小三件套（Compliance Basics）— v0.3（对齐 API v0.3）

版本：**v0.3**  
适用范围：Fermat Assessment Platform（FAP）在 **中国大陆（CN_MAINLAND）** 的 MBTI 主流程（测评 → 报告 → 分享 → 增长）。  
目标：阶段二前不追求“完备合规系统”，先把 **可执行、可解释、可处理** 的最小闭环跑起来。

---

## 0. v0.3 本次修订要点（对齐 API）

本文件对齐 `docs/03-stage1/api-v0.3-spec.md` 的 v0.3 修订版，明确以下新增点的合规口径：

### 0.1 新增字段（可能涉及个人信息/可识别信息）

- `content_package_version`：内容资产包版本号（用于报告装配/回溯）
- `scores_pct`：五轴百分比（0–100）
- `axis_states`：五轴强度状态（very_weak/weak/moderate/clear/strong/very_strong）
- `share_id`：分享卡数据返回的内部追踪 ID（用于统计与反作弊/排障）

### 0.2 新增接口（涉及分享与追踪）

- `GET /api/v0.3/attempts/{attempt_id}/share`  
  返回“分享模板渲染所需字段”，前端生成分享图片；生成成功后上报 `share_generate` 事件。

---

## 1. 合规范围与原则（v0.3 最小口径）

### 1.1 范围

- 本文件只覆盖：**MBTI v0.3** 主流程相关的数据与处理动作：
  - `attempts`（作答记录）
  - `results`（结果记录）
  - `events`（埋点事件）
  - 内容版本字段：`profile_version`、`content_package_version`
  - 分享链路字段：`share_id`（以及 `share_generate` 事件）

### 1.2 最小合规原则（可以落地执行）

- **最小化采集**：只采集生成结果与改进产品所必需的数据；不采集不必要的敏感信息。
- **目的限定**：数据仅用于生成测评结果、生成分享卡、统计漏斗、排障与产品改进；**不对外售卖**，不在未告知前提下用于广告定向。
- **可删除/可导出**：用户提出删除或导出请求时，必须可执行（阶段二前允许人工流程）。
- **可解释**：用户能理解“你收集了什么、为什么收集、保存多久、怎么联系你”。

---

## 2. 数据分类（v0.3）

### 2.1 业务数据（核心）

- `attempts`
  - `attempt_id`（UUID）
  - `anon_id`（匿名标识）
  - `scale_code` / `scale_version`
  - `question_count`
  - `answers_summary_json`（作答摘要）
  - `client_platform` / `client_version` / `channel` / `region` / `locale`
  - `started_at` / `submitted_at`

- `results`
  - `result_id`（UUID）
  - `attempt_id`
  - `type_code`（如 ENFJ-A）
  - `scores_raw`（原始分数）
  - `scores_pct`（v0.3 新增：五轴百分比）
  - `axis_states`（v0.3 新增：五轴状态）
  - `profile_version`
  - `content_package_version`（v0.3 新增）
  - `is_valid`
  - `computed_at`

### 2.2 埋点数据（行为数据）

- `events`
  - `event_code`（scale_view/test_start/test_submit/result_view/share_generate…）
  - `anon_id` / `user_id`（v0.3 预留 user）
  - `scale_code` / `scale_version`
  - `attempt_id`（若有关）
  - `channel` / `region` / `locale`
  - `client_platform` / `client_version`
  - `occurred_at`
  - `meta_json`（扩展字段）

### 2.3 识别与风险分级（阶段二前的实际做法）

- **anon_id**：在 v0.3 语境下属于“可关联同一用户多次行为的标识”，应视作 **可识别性较高的标识符**（即便不含实名）。
- **answers_summary_json / scores_pct / axis_states / type_code**：属于个人测评结果与偏好信息，需按“更敏感的个人信息”对待（内部访问受控、最小展示）。

---

## 3. 数据用途与告知（对外说明口径）

### 3.1 我们记录什么

- 为了生成 MBTI 报告：记录作答（摘要）、计算结果、内容版本。
- 为了让你反复打开同一份报告：记录 `attempt_id → result` 的关系。
- 为了统计与改进产品：记录关键事件（如开始、提交、查看结果、生成分享卡）。
- 为了版本回溯：记录 `profile_version` 与 `content_package_version`。

### 3.2 我们用来做什么（必须写清楚）

- 生成你的结果与报告展示
- 生成分享卡（你点击生成后）
- 统计漏斗与周报（如近 7 天 scale_view/test_submit/share_generate）
- 发现异常与排障（如某版本崩溃、某渠道异常）

### 3.3 我们不会做什么（建议写进对外页面）

- 不出售给第三方
- 不在未告知前提下将测评结果用于广告定向或外部画像
- 不要求用户提供真实姓名/身份证等信息才能完成测评（阶段二前保持）

---

## 4. 数据保存与访问控制（v0.3 建议口径）

### 4.1 保存期限（阶段二可先写“最小承诺”）

建议先采用：

- `events`：保存 90 天（可做漏斗与增长复盘）
- `attempts/results`：保存 180 天（方便用户回溯报告）
- 之后可按实际产品策略调整，但**必须同步更新对外说明页**

> 如果你暂时不想承诺具体天数，也可以写“在实现目的所需期间内保存”，但建议尽快落到具体数字。

### 4.2 访问控制（最小要求）

- 后台只读页面：
  - 默认只展示 `anon_id`、时间、type_code、profile_version、content_package_version
  - 不在列表页展示 `answers_summary_json` 全量内容（仅在详情页，且仅你自己可见）
- 日志：
  - 错误日志不得输出 `answers_summary_json` 原文
- 数据库权限：
  - 生产环境限制只允许应用账号访问；个人开发者不要将生产库开放公网

---

## 5. 用户权益最小通道（v0.3）

阶段二前只做“最小可执行”，允许人工流程，但必须**写清楚**。

### 5.1 对外入口（必须有）

你需要在 `fermatmind.com` 放一个公开页面（最简可用）：

- 推荐路径：`/privacy/mbti-v0.3` 或 `/user-rights`

页面至少包含：

1. 我们记录了什么数据（按第 2 节列出）
2. 数据用途（按第 3 节列出）
3. 你可以提出哪些请求：
   - 删除本人测评数据
   - 导出/查看本人测评记录
4. 如何联系我们：
   - 建议邮箱：`privacy@fermatmind.com`（或你的官方邮箱）
   - 可选：微信客服号/公众号客服入口

### 5.2 用户请求类型（v0.3 最小支持）

- **删除请求（Delete Request）**
  - 删除指定 `anon_id` 关联的：
    - `attempts`
    - `results`
    - `events`
- **导出请求（Export Request）**
  - 导出指定 `anon_id` 的：
    - 最近 N 次 attempts/results 摘要（可 JSON/CSV）
    - events 汇总（可选）

---

## 6. 人工处理 SOP（阶段二前可直接照做）

### 6.1 收到请求时要用户提供什么（最少信息）

用户邮件模板（建议你放到对外页面里）：

- `anon_id`（优先）
- 或提供以下任一组合协助定位：
  - 最近一次测评时间（大概到天/小时）
  - `type_code`（如 ENFJ-A）
  - 设备/平台（小程序或网页）

> 注：阶段二前你不一定给用户展示 anon_id。  
> 你可以在结果页加一个“数据标识/查询码”（显示 anon_id 或短码），方便用户发起权益请求。若暂不做 UI，也可让用户发“测评时间 + 类型 + 渠道”协助你定位。

### 6.2 处理删除请求（建议流程）

1. 在后台或数据库中定位该 `anon_id`
2. 确认范围：
   - 是否仅 MBTI（scale_code=MBTI）
   - 是否包含 events（建议包含）
3. 执行删除：
   - 删除 `attempts`、`results`、`events` 中该 anon_id 的记录
4. 记录审计（最简）：
   - 记录一条内部 note：处理时间、anon_id、操作者、处理类型（delete/export）
5. 回复用户完成

### 6.3 处理导出请求（建议流程）

1. 定位该 `anon_id` 的 attempts/results
2. 导出字段（建议最小集）：
   - attempts：attempt_id、submitted_at、scale_version
   - results：type_code、scores_pct、axis_states、profile_version、content_package_version
3. 以附件形式提供（JSON/CSV 均可）
4. 回复用户完成

---

## 7. 与 API v0.3 对齐的“字段/接口合规说明”

### 7.1 content_package_version（新增字段）

- 目的：支持内容包发布/回滚/回溯旧报告
- 风险：低（版本号本身不是个人信息），但可用于定位“某批用户看到的内容”
- 处理：允许出现在 stats 与后台列表中

### 7.2 scores_pct / axis_states（新增字段）

- 目的：支持动态文案分档与“弱特质处理”
- 风险：中（属于测评结果的细粒度信息）
- 处理：
  - 前端展示：可以展示给用户（属于“给用户的结果”）
  - 后台展示：建议只展示汇总/必要字段，避免在列表页泄露过多细节

### 7.3 share_id（新增字段）

- 目的：分享卡链路统计、排障、反作弊（未来可扩展）
- 风险：中（若 share_id 可被外部枚举/查询，会间接泄露信息）
- 处理：
  - share_id 仅作为内部追踪标识
  - 不提供 `GET /share/{share_id}` 这种可被枚举的公开读取接口（v0.3 不提供）
  - 分享卡对外传播应通过“图片 + 小程序入口”，而不是暴露 share_id

### 7.4 新增接口：GET /attempts/{attempt_id}/share

- 合规关键点：
  - 接口返回的字段应是“分享模板必要字段”，避免返回 `answers` 或任何可重构个人作答的内容
  - 建议包含：type_code/type_name/tagline/rarity/keywords/short_summary
  - 前端生成图片后触发 `share_generate` 事件，事件 meta 可带 share_style，但不带敏感内容

---

## 8. 最小合规自检清单（v0.3）

上线/发布前，你至少要确认：

- [ ] 公开页面已上线：`/privacy/mbti-v0.3` 或 `/user-rights`
- [ ] 页面写清：收集数据、用途、删除/导出请求方式、联系邮箱
- [ ] 生产日志不输出 answers 明文（尤其 answers_summary_json）
- [ ] 后台列表页不直接展示 answers_summary_json 全量
- [ ] share 接口不返回作答明细/可逆信息
- [ ] 删除/导出 SOP 文档存在且你自己能按步骤执行一次

---

## 9. 附：对外页面建议文案骨架（可直接复制到网站）

标题：**费马测试（Fermat Assessment Platform）用户数据与权益说明（MBTI v0.3）**

1. 我们会记录哪些数据  
   - 匿名标识（anon_id）  
   - 测评记录（attempts）与结果（results）  
   - 使用行为事件（events，如开始、提交、查看结果、生成分享卡）  
   - 内容版本号（profile_version、content_package_version）

2. 数据用途  
   - 生成与展示测评结果  
   - 生成分享卡（当你主动点击生成时）  
   - 统计与改进产品体验  
   - 排查故障与安全风控

3. 你的权利  
   - 你可以请求删除你的测评数据  
   - 你可以请求导出/查看你的测评记录

4. 如何联系我们  
   - 邮箱：privacy@fermatmind.com  
   - 请在邮件中提供：anon_id（如有）或测评时间/类型/平台信息以便我们定位

---