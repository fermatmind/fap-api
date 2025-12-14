# FAP v0.2 · MBTI Minimal DB Schema（Stage 2 Skeleton）

> 目标：为「MBTI 单量表 · 中国大陆首发版本」设计**最小可用**的数据库结构，支撑：
> - 记录一次作答（attempt）
> - 计算并持久化一次结果（result）
> - 记录关键行为事件（events），用于后续 D1 数据看板  
> 同时保证未来可以扩展到多量表 / 多地区，而不破坏当前结构。

当前范围（Scope）：

- Product：MBTI 单量表
- Region：CN_MAINLAND
- Locale：zh-CN
- Pricing：FREE（Stage 2 全部免费）
- 框架：Laravel 12.x
- 本地环境：优先 SQLite，线上再迁移 MySQL

---

## 0. 设计约定（Conventions）

1. **ID 策略**
   - 应用层统一使用 UUID（例如 `Str::uuid()->toString()`）。
   - DB 层可以：
     - 用 `char(36)` 保存 UUID；
     - 也可以在未来引入自增 `bigint id` 再单独保存 `attempt_uuid` 等字段。
   - Skeleton 阶段：**直接把 UUID 存在 `id (char(36))` 字段**，足够用。

2. **时间字段**
   - 所有“创建时间”统一用 `created_at`。
   - 需要更新的表加 `updated_at`。
   - 事件时间统一使用 UTC 存库（Laravel 默认），前端展示时再按时区转换。

3. **JSON 字段**
   - 对未来结构可能变动、又不想现在拆很多小表的内容，用 `json`：
     - `answers_summary_json`
     - `scores_json`
     - `meta_json`

4. **命名**
   - 表名：全小写 + 下划线：`attempts`, `results`, `events`
   - 量表与类型 code：大写字母：`MBTI`, `ENFJ-A` 等。
   - 事件 code：小写 snake case：`scale_view`, `test_start`, `test_submit`, `result_view`, `share_generate`。

---

## 1. 核心表概览（Core Tables）

Stage 2 DB 只需要 3 张主表：

1. `attempts`  
   一条记录 = 某个匿名用户对某个量表的一次作答（无论是否成功提交）。
2. `results`  
   一条记录 = 对某次 attempt 的计算结果（人格类型 + 维度分数）。
3. `events`  
   一条记录 = 一次关键行为事件（进入量表页、开始作答、提交、查看结果、生成分享卡片等）。

---

## 2. `attempts` 表

> 记录一次作答的“外壳信息”和简要答案摘要。  
> 对应接口：`POST /api/v0.2/attempts`。

### 2.1 业务用途

- 支持：
  - 通过 `attempt_id` 找回对应结果。
  - 以匿名用户为单位，分析 MBTI 量表的作答行为（做了几次、成功提交几次）。
- Stage 2 暂不拆 `attempt_answers` 子表，先用 JSON 摘要。

### 2.2 字段设计（Logical Schema）

| 字段名                 | 类型（逻辑）     | 说明 |
|------------------------|------------------|------|
| `id`                   | string (UUID)    | attempt 主键 ID（由后端生成并返回给前端） |
| `anon_id`              | string           | 匿名用户标识（如 openid 映射出来的内部 ID） |
| `user_id`              | string \| null   | 预留：未来有登录系统后，绑定正式 user_id |
| `scale_code`           | string           | 量表代码，本阶段固定为 `MBTI` |
| `scale_version`        | string           | 量表版本，例如 `v2.5` |
| `question_count`       | integer          | 当次量表题目总数（例如 144） |
| `answers_summary_json` | json             | 答案摘要：可存每个维度 A/B 计数，如 `{"EI":{"A":12,"B":4}, "SN":...}` |
| `client_platform`      | string           | 客户端平台：`wechat-miniprogram` / `web` 等 |
| `client_version`       | string \| null   | 客户端版本号（小程序版本、前端版本等） |
| `channel`              | string \| null   | 来源渠道：`wechat_ad` / `pdd` / `organic` 等 |
| `referrer`             | string \| null   | 上一个页面 / 外部来源 URL/标识 |
| `started_at`           | datetime \| null | 用户点击「开始测试」的时间 |
| `submitted_at`         | datetime \| null | 用户点击「提交」时的时间 |
| `created_at`           | datetime         | 记录创建时间（Laravel 自动） |
| `updated_at`           | datetime         | 记录更新时间（Laravel 自动） |

### 2.3 建表示例（MySQL）

> 实际迁移用 Laravel migration 写，这里只是结构示意。

```sql
CREATE TABLE attempts (
  id CHAR(36) PRIMARY KEY,
  anon_id VARCHAR(64) NOT NULL,
  user_id VARCHAR(64) NULL,
  scale_code VARCHAR(32) NOT NULL,
  scale_version VARCHAR(16) NOT NULL,
  question_count INT NOT NULL,
  answers_summary_json JSON NOT NULL,
  client_platform VARCHAR(32) NOT NULL,
  client_version VARCHAR(32) NULL,
  channel VARCHAR(32) NULL,
  referrer VARCHAR(255) NULL,
  started_at DATETIME NULL,
  submitted_at DATETIME NULL,
  created_at DATETIME NOT NULL,
  updated_at DATETIME NOT NULL,
  INDEX idx_attempts_anon_scale (anon_id, scale_code),
  INDEX idx_attempts_created_at (created_at)
);