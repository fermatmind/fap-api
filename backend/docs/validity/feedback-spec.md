# Validity Feedback v0.2 规范（MVP）

版本：v0.1  
更新时间：2026-01-21

---

## 0. 目标
为报告提供“有效性/满意度”反馈采集入口，**不影响主链路**；同一 attempt 同日幂等。

---

## 1) API
`POST /api/v0.3/attempts/{attempt_id}/feedback`

- 需要 `Authorization: Bearer <fm_token>`（走 `FmTokenAuth`）
- Feature Flag：`FEEDBACK_ENABLED=0/1`（默认 0）

### 1.1 Feature Flag 行为
- `FEEDBACK_ENABLED=0` → 返回：`{ok:false,error:"NOT_ENABLED"}`（HTTP 200）

### 1.2 请求体（JSON）
字段：
- `score` (required, int) 评分：`1..5`
- `reason_tags` (optional, array<string>) 原因标签列表
- `free_text` (optional, string) 自由文本

校验规则（与后端一致）：
- `score`：必填，`integer`，`min:1`，`max:5`
- `reason_tags`：可选数组，`max:10`
  - 单个 tag：`string`，`max:32`
- `free_text`：可选字符串，`max:200`

### 1.3 服务端清洗（强制）
- `reason_tags`：
  - 去除控制字符（`[\x00-\x1F\x7F]`）
  - `trim` 后为空则丢弃
  - 最多保留 10 个
- `free_text`：
  - 去除控制字符（`[\x00-\x1F\x7F]`）
  - `trim` 后为空 → 存 `null`
  - 截断到 200 字符（优先 `mb_substr`）

> 注意：前端不得传 `pack_id / pack_version / report_version / type_code`，服务端自行绑定。

---

## 2) 返回
### 2.1 成功（首次写入）
```json
{
  "ok": true,
  "feedback_id": 123,
  "created_at": "2026-01-21T08:18:37.123Z"
}
```

### 2.2 成功（同 attempt_id 当日幂等）
`existing=true` 表示当日已存在记录（由 `attempt_id + created_ymd` 唯一约束保障）：
```json
{
  "ok": true,
  "existing": true,
  "feedback_id": 123,
  "created_at": "2026-01-21 08:18:37"
}
```

### 2.3 错误
- `NOT_ENABLED`：`FEEDBACK_ENABLED=0`（HTTP 200）
- `NOT_FOUND`：attempt 不存在（HTTP 404）
- `FORBIDDEN`：身份不匹配（HTTP 403）
- `UNAUTHORIZED`：fm_token 缺失/非法（HTTP 401，来自 `FmTokenAuth`）
- 参数校验失败：HTTP 422（Laravel validate）

---

## 3) 权限规则（最小实现 + TODO）
身份来自 `FmTokenAuth` 注入的属性：`fm_user_id`、`anon_id`。

### 3.1 已登录用户（fm_user_id 存在）
- 若 `attempts.user_id` 字段存在：
  - 只有当 `attempts.user_id == fm_user_id` 才允许写入

### 3.2 匿名用户（fallback）
- 当 `fm_user_id` 为空 **或** `attempts.user_id` 字段不存在时，走 anon 逻辑：
  - 必须存在 `anon_id`，否则 `FORBIDDEN`
  - 若 `identities` 表存在且含 `attempt_id / anon_id`：
    - 需要 `identities.anon_id == anon_id`
  - 若 `identities` 表缺失 / 字段缺失 / 行缺失：**暂时放行**（TODO）

TODO（未来收紧）：
- `identities` 完整可用后，应强制校验 attempt 归属关系；不匹配直接拒绝。

---

## 4) 服务端绑定字段（不可由前端传入）
来源顺序如下（与实现一致）：

### 4.1 pack_id
- 优先：`attempts.pack_id`（若列存在且非空）
- 否则：从 `attempts.result_json` 解析（若列存在）
  - 读取路径：
    - `content_pack_id`
    - `versions.content_pack_id`
    - `report.versions.content_pack_id`
- 再否则：从 `reports/{attempt_id}/report.json` 读取
  - 读取路径：`versions.content_pack_id`

### 4.2 pack_version
- 从 `pack_id` 解析：取最后一个 `.v` 之后的子串
  - 例：`MBTI.cn-mainland.zh-CN.v0.2.2` → `v0.2.1-TEST`

### 4.3 report_version
- `REPORT_VERSION`（env）
- fallback：`config('app.version','')`

### 4.4 type_code
- 仅当 `attempts.type_code` 列存在时取值，否则空字符串

---

## 5) 隐私
- `ip_hash = sha256(ip + env('IP_HASH_SALT','local_salt'))`
- 仅存 hash，不存明文 IP

---

## 6) 数据表（validity_feedbacks）
关键字段：
- `id` (bigint, pk)
- `attempt_id` (uuid, index)
- `fm_user_id` (string 64, nullable, index)
- `anon_id` (string 64, nullable, index)
- `ip_hash` (string 64, index)
- `score` (unsigned tinyint)
- `reason_tags_json` (text)  // 经过清洗后的 tags JSON
- `free_text` (string 200, nullable)
- `pack_id` (string 128, index)
- `pack_version` (string 64, index)
- `report_version` (string 64, index)
- `type_code` (string 16, index)
- `created_at` (timestamp)
- `created_ymd` (string 10)

约束/索引：
- Unique：`(attempt_id, created_ymd)` → **同 attempt_id 当日幂等**
- Index：`(pack_id, pack_version, report_version, created_at)`
- Index：`(score, created_at)`

---

## 7) 最小示例
### cURL
```bash
curl -X POST \
  -H "Authorization: Bearer fm_xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" \
  -H "Content-Type: application/json" \
  -d '{"score":4,"reason_tags":["too_long","not_accurate"],"free_text":"整体还可以"}' \
  "http://127.0.0.1:18000/api/v0.3/attempts/<attempt_id>/feedback"
```

---

## 8) 不影响主链路的降级策略
- 未开启 / 未命中 / 插入失败：不影响 /attempts/{id}/report 主路径
- 幂等与权限失败均返回显式错误码
