# FAP v0.2 领域词典（Glossary）— v0.2.1（对齐 API / 合规 / 发布）

> FAP = Fermat Assessment Platform  
> 适用范围：`v0.2.x`（MBTI 主流程）所有后端 / 前端 / 数据分析文档 & 代码。  
> 本版本：**v0.2.1**（新增：动态报告相关字段、分享接口口径、用户权益相关术语）

---

## 0. 命名总原则

- **机器名用英文 + 下划线**：`scale_code`、`attempt_id`、`event_code`
- **字段命名统一 snake_case**：`content_package_version`、`scores_pct`、`axis_states`
- **对外展示可用中英文**：页面标题、运营文案、分享卡
- **可复用概念必须登记到本词典**：任何新字段/新口径/新事件 → 先更新这里再上代码与内容包

---

## 1. 平台与环境（Platform & Environment）

### 1.1 Fermat Assessment Platform（FAP）

- **中文**：费马测试平台 / 费马测评平台
- **说明**：承载所有在线测评、报告生成、内容分发、分享资产、埋点与数据复盘的技术平台。
- **典型用法**：`FAP v0.2.x`、域名 `fermatmind.com`、微信小程序「费马测试」。

### 1.2 APP_ENV

- **类型**：`string`（环境枚举）
- **枚举值**：
  - `local`：本地开发环境
  - `staging`（预留）：预发布环境
  - `production`：正式线上环境
- **说明**：用于区分 DB/Redis/日志/域名等配置边界。

### 1.3 region（地区）

- **字段名**：`region`
- **类型**：`string(32)`
- **示例值**：
  - `CN_MAINLAND`
  - `GLOBAL`（预留）
- **说明**：宏观地区口径，用于合规与内容分发（例如不同地区不同隐私说明/文案）。

### 1.4 locale（语言）

- **字段名**：`locale`
- **类型**：`string(16)`（IETF 语言标签）
- **示例值**：
  - `zh-CN`
  - `en-US`（预留）
- **说明**：前端展示语言与内容包选择的重要切片维度。

---

## 2. 用户与身份（User & Identity）

### 2.1 用户（User）

- **表 / 模型**：`users` / `App\Models\User`（预留）
- **说明**：注册或绑定账号的真实用户主体（v0.2 允许未登录流程）。

### 2.2 匿名标识（anon_id）

- **字段名**：`anon_id`
- **类型**：`string(64)`
- **说明**：
  - 前端生成/保存的匿名用户 ID（可为 UUIDv4 或小程序侧映射后的稳定标识）
  - 未登录时用于归集同一人的多次测评、事件与分享行为
- **示例**：`wxapp:8f1a0c5e-...`

### 2.3 user_id

- **字段名**：`user_id`（nullable）
- **说明**：登录/绑定后写入，用于长期追踪；未登录为 `null`。

---

## 3. 量表与题库（Scales & Questions）

### 3.1 量表（Scale）

- **字段名**：`scale_code`
- **类型**：`string(32)`
- **说明**：代表一套测评工具的“代码名”，贯穿所有表/事件/接口。
- **示例**：
  - `MBTI`
  - `IQ_BASIC`（预留）
  - `DEPRESSION_PHQ9`（预留）

### 3.2 量表版本（scale_version）

- **字段名**：`scale_version`
- **类型**：`string(16)`
- **示例**：`v0.2`、`v0.3`
- **说明**：题库与评分规则的技术版本号。
  - **改题/改评分**必须提升 `scale_version`，保证旧结果可追溯与可重算。

### 3.3 题目（Question）

- **核心字段**：
  - `question_id`：题目唯一 ID（`<SCALE>-<序号>`），如 `MBTI-001`
  - `order`：展示顺序
  - `dimension`：所属评分维度（如 `EI`/`SN`）
  - `text`：题干
  - `options`：选项（`code` + `text`）

**响应结构示例：**

    {
      "question_id": "MBTI-001",
      "order": 1,
      "dimension": "EI",
      "text": "当你在一个新环境时，更容易感到：",
      "options": [
        { "code": "A", "text": "主动跟人打招呼、聊起来很快" },
        { "code": "B", "text": "先观察环境，慢慢再融入" }
      ]
    }

### 3.4 维度（Dimension）

MBTI 主维度：
- **EI**：外向 – 内向（Extraversion / Introversion）
- **SN**：感觉 – 直觉（Sensing / iNtuition）
- **TF**：思考 – 情感（Thinking / Feeling）
- **JP**：判断 – 感知（Judging / Perceiving）

附加维度（费马版）：
- **AT**：自信 – 敏感（Assertive / Turbulent）
- 用于生成 **-A / -T** 后缀（如 **ENFJ-A / INTP-T**）

---

## 4. 作答与结果（Attempts & Results）

### 4.1 Attempt（一次作答）

- **表 / 模型**：`attempts` / `App\Models\Attempt`
- **说明**：一次完整的测评作答会话（从开始到提交），是结果与埋点的主关联键。
- **字段关键点**：
  - `attempt_id`：UUIDv4（主键）
  - `anon_id` / `user_id`
  - `scale_code` / `scale_version`
  - `question_count`
  - `answers_summary_json`：作答概要（用于审计/重算/质检）
  - `client_platform` / `client_version` / `channel` / `referrer`
  - `region` / `locale`
  - `started_at` / `submitted_at`

### 4.2 Result（一次结果）

- **表 / 模型**：`results` / `App\Models\Result`
- **说明**：Attempt 的计算产物；原则上“可重算、可追溯、可回放”。
- **字段关键点**：
  - `result_id`：UUIDv4（主键）
  - `attempt_id`（外键）
  - `scale_code` / `scale_version`
  - `type_code`：如 `ENFJ-A`
  - `scores_json`：五轴分数（原始分或计分结果）
  - `scores_pct`（v0.2.1 新增，见 6.1）
  - `axis_states`（v0.2.1 新增，见 6.2）
  - `profile_version`：结果文案版本（类型骨架/长文结构版本）
  - `content_package_version`（v0.2.1 新增，内容包版本）
  - `computed_at`
  - `is_valid`

---

## 5. 内容版本与内容包（Content Versioning）

v0.2.1 新增：`content_package_version`（内容包版本），用于动态报告与分享资产的版本化发布/回滚。

### 5.1 profile_version（文案版本）

- **字段名**：`profile_version`
- **类型**：`string(32)`
- **示例**：`mbti32-v2.5`
- **说明**：用于返回 profile（类型骨架/结构化长文）的版本追溯。

### 5.2 content_package_version（内容包版本）— v0.2.1 新增

- **字段名**：`content_package_version`
- **类型**：`string(32)`
- **示例**：
  - `MBTI-CN-v0.2.1`
  - `MBTI-CN-v0.2.1-hotfix1`
- **说明**：
  - 用于动态报告/分享资产/卡片库的统一版本号
  - 发布/灰度/回滚以该版本为最小单位
  - 可与 `profile_version` 并存：`profile_version` 管“类型骨架”，`content_package_version` 管“动态模块与分享模板”。

### 5.3 content asset（内容资产）

- **定义**：可版本化发布的文案/卡片/模板/声明文本等内容集合。
- **示例资产类型（v0.2.1 口径）**：
  - `type_profile`：类型骨架（32 条）
  - `share_template`：分享卡字段模板
  - `disclaimer_text`：免责声明/合规提示文案
  - `content_graph_node`：推荐阅读节点（可选）
  - `axis_dynamics`（预留到后续更细化版本）
  - `layer_profiles`（预留到后续更细化版本）

---

## 6. 动态报告字段（Dynamic Report Fields）— v0.2.1 新增

v0.2.1 的核心变化：结果返回除了 `scores_json`，新增面向前端渲染的“稳定结构字段”，用于实现同型不同百分比差异化。

### 6.1 scores_pct（五轴百分比）

- **字段名**：`scores_pct`
- **类型**：`object`
- **示例**：`{ "EI": 58, "SN": 71, "TF": 62, "JP": 84, "AT": 66 }`
- **说明**：
  - 0–100 的倾向强度表达
  - 口径：不是“能力/优劣”，而是“该轴两端之间的位置与支持强度”

### 6.2 axis_states（五轴状态机输出）

- **字段名**：`axis_states`
- **类型**：`object`
- **枚举值**：`very_weak` / `weak` / `moderate` / `clear` / `strong` / `very_strong`
- **示例**：`{ "EI": "weak", "SN": "clear", "TF": "moderate", "JP": "strong", "AT": "moderate" }`
- **说明**：
  - 由后端按阈值配置计算（阈值可运营可调）
  - 用于动态选择文案卡片与语气控制（前端不做规则）

### 6.3 highlights / sections.cards（动态卡片输出形态）

- **字段名（建议口径）**：
  - `highlights[]`
  - `sections.{traits|career|growth|relationships}.cards[]`
- **说明**：
  - 前端不参与规则，只渲染卡片数组
  - v0.2.1 可先作为“结构预留字段”，内容逐步填充

---

## 7. 类型代码（type_code）

### 7.1 type_code（人格类型代码）

- **字段名**：`type_code`
- **示例**：`ENFJ-A`、`ISTP-T`
- **结构**：四字母主类型 + `-` + `A/T`
- **说明**：
  - 前四位为 MBTI 经典 16 型
  - 末位为费马版 A/T（来自 AT 维度）

---

## 8. 客户端与渠道（Client & Channel）

### 8.1 client_platform

- **字段名**：`client_platform`
- **类型**：`string(32)`
- **枚举建议**：
  - `wechat-miniprogram`
  - `web-h5`
  - `web-desktop`
  - `other`

### 8.2 client_version

- **字段名**：`client_version`
- **类型**：`string(32)`
- **说明**：前端版本号，用于排查版本差异。

### 8.3 channel（渠道）

- **字段名**：`channel`
- **类型**：`string(32)`
- **示例值**：`dev`、`organic`、`wechat-ad`、`pdd`、`bilibili`、`xiaohongshu`、`douyin`

### 8.4 referrer

- **字段名**：`referrer`
- **类型**：`string(255)`
- **说明**：来源页/落地页地址，可为空。

---

## 9. 事件与埋点（Events & Tracking）

### 9.1 Event（事件）

- **表 / 模型**：`events` / `App\Models\Event`
- **说明**：用于指标统计、漏斗分析、异常排查、归因与复盘。
- **核心字段**：
  - `event_id`：事件 ID（自增或 UUID）
  - `event_code`
  - `anon_id` / `user_id`
  - `scale_code` / `scale_version`（若有关）
  - `attempt_id`（若有关）
  - `channel` / `region` / `locale`
  - `client_platform` / `client_version`
  - `occurred_at`
  - `meta_json`

### 9.2 event_code（事件类型代码）

v0.2.x 核心 5 类：
1. `scale_view`
2. `test_start`
3. `test_submit`
4. `result_view`
5. `share_generate`

预留扩展：
- `share_click`（从分享落地页进入/打开）
- `privacy_view`（用户查看隐私/权益说明）
- `delete_request_submit`（提交删除请求）
- `export_request_submit`（提交导出请求）

---

## 10. 分享与追踪（Share & Attribution）— v0.2.1 新增

### 10.1 share_id

- **字段名**：`share_id`
- **类型**：`string(64)`（建议 UUID 或短码）
- **说明**：
  - 用于追踪分享链路（生成分享卡/落地页访问/带来新增）
  - 不应作为用户可识别“档案号”对外展示
  - 可写入 `events.meta_json` 作为归因线索

### 10.2 Share Payload（分享卡模板数据）

- **定义**：后端为分享卡生成提供的“最小字段协议”
- **示例字段**：
  - `type_code` / `type_name` / `tagline` / `rarity`
  - `keywords[]` / `short_summary`
  - `content_package_version`
  - `share_id`

---

## 11. API 端点术语索引（Endpoints Index）— v0.2.1 补齐

这里仅登记“端点名称与用途口径”，详细请求/响应以 `docs/api-v0.2-spec.md`（v0.2.1）为准。

### 11.1 Ping

- `GET /api/v0.2/ping`：健康检查

### 11.2 Scale / Questions

- `GET /api/v0.2/scales/mbti`（或统一 `GET /scales/{code}`）：拉取量表配置与题目列表

### 11.3 Attempts

- `POST /api/v0.2/attempts`：提交一次作答（写入 attempts + results，事务一致性）
- `GET /api/v0.2/attempts/{attempt_id}/result`：读取结果（只读，不重复写 result）

### 11.4 Share — v0.2.1 新增

- `GET /api/v0.2/attempts/{attempt_id}/share`：获取分享模板数据（含 share_id、content_package_version）

### 11.5 Events

- `POST /api/v0.2/events`：上报单条埋点事件（写入 events）

### 11.6 Stats

- `GET /api/v0.2/stats/summary`：最近 N 天游标口径汇总（对齐周报/命令）

### 11.7 User Rights（用户权益）— v0.2.1 新增/补齐口径

- `GET /api/v0.2/user-rights`（或 `GET /api/v0.2/privacy/summary`）：返回“我们记录什么/用途/如何删除/如何导出/联系渠道”
- `POST /api/v0.2/user-requests`：提交用户请求（删除/导出等），写入请求记录并可触发事件

---

## 12. 合规与用户权益（Compliance & User Rights）— v0.2.1 补齐

### 12.1 user_request（用户请求）

- **定义**：用户对个人数据提出的请求（删除/导出/更正等）
- **建议字段**：
  - `request_id`（UUID）
  - `request_type`：`delete` / `export`
  - `anon_id` / `user_id`（可选）
  - `status`：`received` / `in_progress` / `completed` / `rejected`
  - `submitted_at` / `completed_at`
  - `notes`（处理备注）

### 12.2 数据删除（Deletion）

- **口径**：删除与该 `anon_id`/`user_id` 相关的测评数据（attempts/results/events）或按合规策略执行最小化处理。

### 12.3 数据导出（Export）

- **口径**：以用户可读格式导出其测评记录摘要（不包含敏感内部实现信息），并通过指定渠道交付（邮件/客服）。

---

## 13. 指标口径（Metrics）— 与 D1 周报对齐

固定 8 指标（v0.2.x）：
1. `scale_view`
2. `test_start`
3. `test_submit`
4. `result_view`
5. `share_generate`
6. `total_events`（1–5 之和）
7. `unique_submit_anon_ids`（test_submit distinct anon_id）
8. `type_code` 分布（来自 results.type_code 聚合）

---

## 14. 错误与状态（Status & Errors）

### 14.1 API 通用响应字段

- `ok`：true/false
- `error`：错误码字符串（成功为 null）
- `message`：简短说明
- `data`：业务数据（失败可为 null）

### 14.2 常见错误码（v0.2.x）

- `RESULT_NOT_FOUND`
- `ATTEMPT_NOT_FOUND`
- `VALIDATION_FAILED`
- `INTERNAL_ERROR`
- `RATE_LIMITED`（若启用限流）
- `USER_REQUEST_INVALID`（用户权益请求参数不合法）

---

## 15. 命名规范速查（Naming Conventions）

### 15.1 ID 规范

- `attempt_id` / `result_id` / `request_id`：UUIDv4
- `question_id`：`<SCALE>-<3位或4位序号>`（如 `MBTI-001`）
- `event_code`：小写 + 下划线（如 `scale_view`）
- `share_id`：UUID 或短码（不可直接暴露为“档案号”）

### 15.2 JSON 字段命名

- 一律 snake_case：  
  `scale_code`、`scale_version`、`profile_version`、`content_package_version`、`scores_pct`、`axis_states`

---

## 16. 后续维护约定（Maintenance）

1. 新增概念：先在本词典增加「英文字段名 + 中文解释 + 示例」
2. 修改口径：必须在本文件标注变更点（例如 v0.2.2 生效）
3. 对外协作：本词典是字段与口径的唯一权威来源
4. 发布/回滚：以 `content_package_version` 为最小单位进行内容资产发布与回滚（详见发布清单）