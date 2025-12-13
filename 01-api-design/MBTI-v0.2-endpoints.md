# FAP v0.2 · MBTI Endpoint Behavior (Stage 2 Skeleton)

> Scope: MBTI-only, CN Mainland-first, Stage 2 “最小可跑骨架”
> Goal: 用文字把 5 个核心接口“应该做什么”讲清楚，为后续 Laravel 实现提供脚本。

---

## 0. 全局约定（与 A2 保持一致）

- 基础路径（spec）  
  - `/api/v0.2/...`
- 身份策略（Stage 2 仍然 anon-first）  
  - 默认：`anon_id`  
  - 预留：`user_id` / `openid`
- 默认上下文  
  - `region`：`CN_MAINLAND`  
  - `locale`：`zh-CN`  
  - `currency`：`CNY`  
  - `price_tier`：`FREE`
- 统一返回 envelope（逻辑）  
  - 成功：
    - `ok: true`
    - `data: { ... }`
    - `meta: { request_id, ts, region, locale, version }`
  - 失败：
    - `ok: false`
    - `error: { code, message, details }`
    - `meta: { ... }`

本文件只描述“职责 & 行为流程”，不涉及具体代码实现。

---

## 1）GET /api/v0.2/health

### 1.1 目的（Purpose）

- 对外：让前端/监控系统判断 **API 服务是否存活**。
- 对内：快速确认 **PHP 应用 + MySQL + Redis** 是否可用。

### 1.2 行为脚本（Behavior）

1. 生成一个 `request_id`（例如 UUID）。
2. 尝试做一次 **MySQL 连接检查**：  
   - 执行一条极简查询（如 `SELECT 1`）。  
   - 若失败，标记 `mysql: error`，并在 `error.details` 写入原因。
3. 如果有 Redis：尝试做一次 **Redis 读取/写入检查**：  
   - 写入一个短 TTL 的 key（如 `health:ping`）。  
   - 读取该 key 确认正常。  
   - 若失败，标记 `redis: error`。
4. 将应用自身状态标记为：  
   - `php: ok`（如果代码运行到这里）  
5. 组合结果：
   - 如果 **所有组件都 OK** → 整体 `status = "ok"`。  
   - 如果有任何组件异常 → 整体 `status = "degraded"` 或 `status = "error"`。
6. 返回统一格式：

```jsonc
{
  "ok": true,                  // 整体调用是否成功（接口本身没崩）
  "data": {
    "status": "ok",            // ok / degraded / error
    "services": {
      "php": "ok",
      "mysql": "ok",           // or "error"
      "redis": "ok"            // or "error" / "unconfigured"
    }
  },
  "meta": {
    "request_id": "uuid",
    "ts": 1730000000,
    "region": "CN_MAINLAND",
    "locale": "zh-CN",
    "version": "v0.2"
  }
}