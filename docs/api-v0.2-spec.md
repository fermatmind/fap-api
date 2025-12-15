API v0.2 规范（FAP / MBTI 主流程）

版本：v0.2
适用范围：Fermat Assessment Platform（费马测试平台）MBTI 主流程相关接口。
目标：支持微信小程序 & Web 端完成一次 MBTI 测试 + 结果展示 + 埋点上报。

⸻

0. 总体约定

0.1 基础信息
	•	基础路径：/api/v0.2
	•	数据格式：application/json; charset=utf-8
	•	字段命名：统一使用 snake_case
	•	时间字段：统一使用 ISO8601 字符串，UTC 时间，例如："2025-12-15T09:30:00Z"

0.2 公共请求头

所有请求建议携带以下 Header（部分可选）：
	•	X-Client-Platform：客户端平台，如 wechat-miniprogram / web-h5
	•	X-Client-Version：前端版本号，如 wx-1.0.3
	•	X-Channel：渠道，如 dev / organic / wechat-ad / pdd
	•	X-Region：区域，如 CN_MAINLAND
	•	X-Locale：语言，如 zh-CN

这些头部将被后端映射到 client_platform / client_version / channel / region / locale 等字段。

0.3 统一响应结构

所有接口返回结构遵循：

{
  "ok": true,
  "error": null,
  "message": "success",
  "data": { /* 具体业务数据 */ }
}

	•	ok: true / false
	•	error: 错误码字符串（成功时为 null）
	•	message: 人类可读的说明
	•	data: 具体业务数据对象或列表，失败时可为 null

常见错误码：
	•	RESULT_NOT_FOUND
	•	ATTEMPT_NOT_FOUND
	•	VALIDATION_FAILED
	•	INTERNAL_ERROR

HTTP 状态码约定：
	•	2xx：业务成功（ok = true）
	•	4xx：客户端请求错误（ok = false）
	•	5xx：服务端异常（ok = false）

⸻

1. 健康检查 / Ping

1.1 GET /api/v0.2/ping

用于前端或监控系统检测 API 是否可用。

请求
	•	无需请求体。

响应示例

{
  "ok": true,
  "error": null,
  "message": "pong",
  "data": {
    "env": "local",
    "version": "v0.2",
    "server_time": "2025-12-15T09:30:00Z"
  }
}


⸻

2. 获取量表配置 & 题目列表（以 MBTI 为例）

支持一次性拉取 MBTI 题目列表（144 题），由前端自行分页展示。

2.1 GET /api/v0.2/scales/mbti

功能
	•	返回当前 MBTI 版本的题目列表与元信息。
	•	只读接口，无副作用。

请求参数（Query）
	•	version (可选)：指定量表版本号，不传则为默认版本。
	•	示例：?version=v0.2

响应数据结构

{
  "ok": true,
  "error": null,
  "message": "success",
  "data": {
    "scale_code": "MBTI",
    "scale_version": "v0.2",
    "question_count": 144,
    "profile_version": "mbti32-v2.5",
    "questions": [
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
      // ...
    ]
  }
}


⸻

3. 创建一次作答 Attempt（提交答案）

前端在用户完成全部题目后，一次性提交所有答案。
后端负责：
	•	写入 attempts 表
	•	根据答案计算各维度分数
	•	写入 results 表
	•	返回 attempt_id 和 type_code

3.1 POST /api/v0.2/attempts

请求体

{
  "scale_code": "MBTI",
  "scale_version": "v0.2",
  "question_count": 144,
  "anon_id": "wxapp:8f1a0c5e-1234-5678-90ab-abcdefabcdef",
  "answers": [
    {
      "question_id": "MBTI-001",
      "option_code": "A"
    },
    {
      "question_id": "MBTI-002",
      "option_code": "B"
    }
    // ...
  ]
}

	•	scale_code: 固定为 "MBTI"（当前版本）
	•	scale_version: 题目版本号
	•	question_count: 本次作答题目总数
	•	anon_id: 匿名用户 ID（前端生成/缓存）
	•	answers: 题目作答列表

服务器端会将部分信息汇总为 answers_summary_json 存入 attempts 表。

响应示例（成功）

{
  "ok": true,
  "error": null,
  "message": "success",
  "data": {
    "attempt_id": "b4e1a8f3-0c91-4a25-a57d-abc123456789",
    "scale_code": "MBTI",
    "scale_version": "v0.2",
    "type_code": "ENFJ-A",
    "scores": {
      "EI": 12,
      "SN": 8,
      "TF": 10,
      "JP": 14,
      "AT": 6
    },
    "profile_version": "mbti32-v2.5",
    "computed_at": "2025-12-15T09:32:00Z"
  }
}

响应示例（参数错误）

{
  "ok": false,
  "error": "VALIDATION_FAILED",
  "message": "answers must contain 144 items.",
  "data": null
}


⸻

4. 获取一次结果详情（Result + Profile）

前端在提交成功后，可使用 attempt_id 拉取完整结果，包括解释文案。

4.1 GET /api/v0.2/attempts/{attempt_id}/result

路径参数
	•	attempt_id：创建作答时返回的唯一 ID（UUID）

请求示例
GET /api/v0.2/attempts/b4e1a8f3-0c91-4a25-a57d-abc123456789/result

响应数据结构（成功）

{
  "ok": true,
  "error": null,
  "message": "success",
  "data": {
    "attempt": {
      "attempt_id": "b4e1a8f3-0c91-4a25-a57d-abc123456789",
      "anon_id": "wxapp:8f1a0c5e-1234-5678-90ab-abcdefabcdef",
      "scale_code": "MBTI",
      "scale_version": "v0.2",
      "question_count": 144,
      "started_at": "2025-12-15T09:30:00Z",
      "submitted_at": "2025-12-15T09:32:00Z"
    },
    "result": {
      "result_id": "53b8b8e0-1b33-4b2e-8a27-abcdef123456",
      "type_code": "ENFJ-A",
      "scores": {
        "EI": 12,
        "SN": 8,
        "TF": 10,
        "JP": 14,
        "AT": 6
      },
      "profile_version": "mbti32-v2.5",
      "computed_at": "2025-12-15T09:32:00Z",
      "is_valid": true
    },
    "profile": {
      "type_code": "ENFJ-A",
      "type_name": "主人公型",
      "tagline": "笃定型领路人",
      "rarity": "约 2%（较为少见）",
      "keywords": [
        "理想主义",
        "高共情",
        "号召力强",
        "有责任感",
        "自信稳重",
        "目标导向"
      ],
      "intro": "你像一束方向感清晰的聚光灯……",
      "traits": {
        "title": "人格特征",
        "paragraphs": [
          "……",
          "……"
        ]
      },
      "career": {
        "title": "适合的学习与职业方向",
        "paragraphs": [
          "……"
        ]
      },
      "relationships": {
        "title": "在人际与亲密关系中的样子",
        "paragraphs": [
          "……"
        ]
      },
      "growth": {
        "title": "成长建议",
        "paragraphs": [
          "……"
        ]
      }
    }
  }
}

响应示例（未找到）

{
  "ok": false,
  "error": "RESULT_NOT_FOUND",
  "message": "Result for given attempt_id not found.",
  "data": null
}


⸻

5. 上报埋点事件（Events）

前端在关键节点调用，用于写入 events 表。
事件类型请参考 fap-v0.2-glossary.md 中 event_code 定义。

5.1 POST /api/v0.2/events

建议支持单条上报；如需批量，可在后续 v0.3 版本扩展。

请求体示例

{
  "event_code": "test_submit",
  "anon_id": "wxapp:8f1a0c5e-1234-5678-90ab-abcdefabcdef",
  "user_id": null,
  "scale_code": "MBTI",
  "scale_version": "v0.2",
  "attempt_id": "b4e1a8f3-0c91-4a25-a57d-abc123456789",
  "channel": "dev",
  "region": "CN_MAINLAND",
  "locale": "zh-CN",
  "client_platform": "wechat-miniprogram",
  "client_version": "wx-1.0.3",
  "occurred_at": "2025-12-15T09:32:05Z",
  "meta": {
    "question_count": 144,
    "profile_version": "mbti32-v2.5",
    "share_style": null
  }
}

后端将 meta 序列化为 meta_json 存储。

响应示例

{
  "ok": true,
  "error": null,
  "message": "logged",
  "data": {
    "event_id": 12345
  }
}


⸻

6. 简易统计接口（供后台 / Cron 使用）

v0.2 提供一个只读统计接口，方便后台页面或外部工具拉取核心指标。
与 php artisan fap:weekly-report 命令使用相同口径。

6.1 GET /api/v0.2/stats/summary

请求参数（Query）
	•	days (可选)：统计最近 N 天，默认 7
	•	scale_code (可选)：默认 MBTI
	•	region (可选)：区域过滤，如 CN_MAINLAND
	•	channel (可选)：渠道过滤，如 wechat-ad

示例：
GET /api/v0.2/stats/summary?days=7&scale_code=MBTI

响应数据结构

{
  "ok": true,
  "error": null,
  "message": "success",
  "data": {
    "range": {
      "days": 7,
      "start": "2025-12-09T00:00:00Z",
      "end": "2025-12-15T23:59:59Z"
    },
    "events": {
      "scale_view": 0,
      "test_start": 0,
      "test_submit": 1,
      "result_view": 1,
      "share_generate": 0,
      "total_events": 2,
      "unique_submit_anon_ids": 1
    },
    "type_distribution": [
      { "type_code": "ENFJ-A", "count": 1 },
      { "type_code": "INTP-T", "count": 0 }
      // ...
    ]
  }
}


⸻

7. 安全与限流（简要）

v0.2 可以只做最小安全措施，后续版本再增强。

	•	建议开启：
	•	基于 IP 的简单限流（如 Laravel throttle 中间件）
	•	针对 POST /attempts 和 POST /events 的速率控制
	•	所有接口使用 HTTPS
	•	敏感日志（如 answers_summary_json）仅内部可见，不在错误信息中回显。

⸻

8. 版本演进说明
	•	v0.2：
	•	确立 MBTI 主流程：拉题 → 提交 → 出结果 → 埋点；
	•	提供最小统计接口 /stats/summary。
	•	未来 v0.3+ 可能新增：
	•	批量事件上报
	•	用户登录 / 历史记录查询
	•	更多量表（IQ / 抑郁筛查 / 关系测评等）共用同一套 API 规范。