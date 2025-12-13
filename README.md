# FAP v0.2 · 中台 API / fap-api

> Version: V0.2-A (Skeleton-level, MBTI-first)  
> Status: Design & Skeleton stage

---

## 1. 服务定位（Service Boundary）

本仓库只做一件事：

> 提供 FAP 统一的中台 API 服务，暴露 `/api/v0.2/...` 接口，负责数据与业务规则，不负责页面渲染。

在 Stage 2（Skeleton）阶段，这个服务：

- **只服务 MBTI 量表**（scale_code = `MBTI`）
- 提供最小闭环接口：
  - `GET /api/v0.2/health`
  - `GET /api/v0.2/scales/MBTI`
  - `GET /api/v0.2/scales/MBTI/questions`
  - `POST /api/v0.2/attempts`
  - `GET /api/v0.2/attempts/{attempt_id}/result`
- 返回结构遵守 **fap-specs/02-api/A2-api-spec-v0.2.md** 中定义的统一 envelope：
  - `ok / data / meta / error` 口径
- 不负责：
  - 不渲染 HTML / 前端页面
  - 不直接处理小程序 UI
  - 不写任何与具体平台耦合的展示逻辑（例如微信组件细节）

---

## 2. 当前阶段范围（Stage 2 Skeleton Scope）

### 2.1 功能范围

- 基础健康检查：
  - `GET /api/v0.2/health` 反映 PHP / MySQL / Redis 等基础组件状态
- MBTI 元信息：
  - `GET /api/v0.2/scales/MBTI` 返回当前 MBTI 版本、题量、内容包版本等
- 取题接口：
  - `GET /api/v0.2/scales/MBTI/questions`  
  - **数据源**：暂时可以是本地 JSON/JSONL 文件（例如从 `fap-specs` 导出的题库）
- 作答提交：
  - `POST /api/v0.2/attempts` 接受一次作答，写入 `attempts` 表
- 结果查询：
  - `GET /api/v0.2/attempts/{attempt_id}/result`  
  - 从 `results` 表 或 静态规则 计算并返回 MBTI 类型 + 维度得分

### 2.2 数据与事件

- 最小数据库表（设计在 `02-db-design/` 中定义）：
  - `attempts`：记录一次作答基本信息
  - `results`：记录 attempt → type_code + 维度分数
  - `events`：按 A3 事件词典写入关键事件
- 事件命名和字段必须符合：
  - `fap-specs/03-events/A3-event-dictionary-v0.2.md`

---

## 3. 技术栈（Tech Stack）

Stage 2 默认技术栈：

- **Backend**：PHP 8.x + Laravel（建议 10+）
- **Database**：MySQL 8.x
- **Cache / Queue（可选）**：Redis
- **Env 管理**：`.env`（local / staging / production）

说明：

- 你现在已经在用 PHP + MySQL，Laravel 自带：
  - 路由 / 中间件 / 迁移 / seeder
  - `.env` 多环境配置
- 未来允许扩展：
  - 某些新量表或重计算逻辑，可用 Node 子服务，但必须通过 HTTP / RPC 接到本 API，而不是直接暴露给前端。

---

## 4. 仓库结构规划（草案）

> 实现阶段可以微调，但大骨架建议保持。

```text
fap-api/
  ├── 00-plan/
  │   └── Stage2-skeleton-checklist.md      # 阶段 2 验收清单
  ├── 01-api-design/
  │   └── MBTI-v0.2-endpoints.md            # 接口设计文档（对齐 A2-spec）
  ├── 02-db-design/
  │   └── mbti-v0.2-schema.md               # attempts / results / events 表结构
  ├── 03-env-config/
  │   └── env-strategy.md                   # local / staging / production 策略
  ├── 04-analytics/
  │   ├── mbti-v0.2-event-mapping.md        # 前后端事件映射
  │   └── D1-mapping-from-events.md         # 事件 → 8 KPI 计算关系
  ├── app/ ...                              # 未来 Laravel 代码
  ├── routes/ ...                           # 未来路由定义
  ├── README.md
  └── ...