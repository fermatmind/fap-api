# FAP API v0.2 · Skeleton (MBTI 最小中台骨架)

> Fermat Assessment Platform · 中台 API 仓库（设计 & 骨架阶段）

---

## 0. 仓库定位 / Repo Purpose

这个仓库专门用来承载：

- **FAP v0.2 的中台 API 服务设计 + 实现**
- **只做「数据 + 业务规则」，不渲染页面**
- 当前阶段只服务 **MBTI 测评**，后续会扩展到 IQ / 情感 / 职业等量表

一句话：  
这里是「费马测评中台」的 **后端大脑**，对外暴露 `/api/v0.2/...` 接口。

---

## 1. 当前阶段（Stage 2 · Skeleton-level）

目前进度：**只到“设计 + 文档骨架”，还没有正式写后端代码。**

已完成 / 进行中的内容：

- ✅ 有了一个独立的 API 仓库：`fap-api`
- ✅ 明确服务定位：中台 API，不负责前端展示
- ✅ 参考 `fap-specs` 仓库，开始分模块设计：
  - API 端点设计（01-api-design）
  - DB 结构草图（02-db-design）
  - 环境与配置策略（03-env-config）
  - 埋点与数据运营方案（04-analytics）
- ⏳ 还未开始：
  - 实际 Laravel 项目初始化
  - 实际路由 / 控制器 / 数据库存储实现

> 这个 README 当前是「**设计阶段版**」，后面等你把 Laravel 项目真搭起来，我们再加上安装与运行步骤。

---

## 2. 预期技术栈 / Planned Tech Stack

**后端框架（推荐）**

- Language: PHP 8.3+
- Framework: Laravel 10/11/12（与你现在服务器上的生态一致）
- Web Server: Nginx
- DB: MySQL
- Cache & Queue: Redis

**为什么先写在 README 里？**

- 即使你还没 `laravel new`，也先把「默认技术栈」写死，  
  未来外包 / 合作者一看 README 就知道要用什么。

---

## 3. 与 `fap-specs` 的关系

- `fap-specs`：**“中台宪法”**  
  - 里面是：领域模型、API 规范、事件词典、内容规范、合规模板、KPI 模板
- `fap-api`：**“中台实现”**  
  - 这里是：根据 `fap-specs` 真正落地的 API、DB、环境、埋点 & 拉数方案

约定：

> 任何接口 / DB 字段 / 事件命名，如果和 `fap-specs` 冲突，  
> 以 `fap-specs` 为准，这个仓库需要回头改。

---

## 4. 当前目录结构（文档骨架）

> 注：以下是 **Stage 2 文档期** 的目录结构，尚未包含 `app/`、`routes/` 等 Laravel 代码。

```txt
fap-api/
  README.md

  00-plan/
    Stage2-skeleton-checklist.md      # 阶段 2 骨架验收清单（打勾用）

  01-api-design/
    MBTI-v0.2-endpoints.md           # MBTI v0.2 具体接口设计草稿

  02-db-design/
    mbti-v0.2-schema.md              # MBTI 最小 DB 表结构草图（attempts/results/events）

  03-env-config/
    env-strategy.md                  # local / staging / production 环境与配置边界策略

  04-analytics/
    mbti-v0.2-event-mapping.md       # A3 事件词典 → 实际 events 表/日志字段映射
    D1-mapping-from-events.md        # D1 每个 KPI 如何从事件/表里算出来
    weekly-ritual.md                 # 每周固定复盘流程模板（谁、哪天、怎么算、怎么记）