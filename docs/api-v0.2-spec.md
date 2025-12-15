# API v0.2 规范（FAP / MBTI 主流程）— v0.2.1 修订版

版本：**v0.2.1（文档修订）**  
基础版本：v0.2  
适用范围：Fermat Assessment Platform（费马测试平台）MBTI 主流程相关接口。  
目标：支持微信小程序 & Web 端完成一次 MBTI 测试 + 结果展示 + 分享卡 + 埋点上报，并为「动态报告引擎」预留扩展位（不破坏 v0.2 路径）。

---

## 0. 总体约定

### 0.1 基础信息

- 基础路径：`/api/v0.2`
- 数据格式：`application/json; charset=utf-8`
- 字段命名：统一使用 `snake_case`
- 时间字段：统一使用 ISO8601 字符串，UTC 时间，例如：`"2025-12-15T09:30:00Z"`

### 0.2 公共请求头（建议）

所有请求建议携带以下 Header（部分可选）：

- `X-Client-Platform`：客户端平台，如 `wechat-miniprogram` / `web-h5` / `web-desktop`
- `X-Client-Version`：前端版本号，如 `wx-1.0.3`
- `X-Channel`：渠道，如 `dev` / `organic` / `wechat-ad` / `pdd`
- `X-Region`：区域，如 `CN_MAINLAND`
- `X-Locale`：语言，如 `zh-CN`

说明：这些头部将被后端映射到 `client_platform / client_version / channel / region / locale` 等字段，并用于 events 入库与统计切片。

### 0.3 统一响应结构

所有接口返回结构遵循：

```json
{
  "ok": true,
  "error": null,
  "message": "success",
  "data": {}
}

	•	ok: true / false
	•	error: 错误码字符串（成功时为 null）
	•	message: 人类可读的说明
	•	data: 具体业务数据对象或列表，失败时可为 null

常见错误码（v0.2）：
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

请求：无需请求体。

响应示例：

{
  "ok": true,
  "error": null,
  "message": "pong",
  "data": {
    "env": "local",
    "version": "v0.2",
    "spec_revision": "v0.2.1",
    "server_time": "2025-12-15T09:30:00Z"
  }
}


⸻

2. 获取量表配置 & 题目列表（MBTI）

支持一次性拉取 MBTI 题目列表（144 题），由前端自行分页展示。

2.1 GET /api/v0.2/scales/mbti

功能
	•	返回当前 MBTI 版本的题目列表与元信息
	•	只读接口，无副作用

请求参数（Query）
	•	version（可选）：指定量表版本号，不传则为默认版本
示例：?version=v0.2

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
    "content_package_version": "MBTI-CN-v0.2",
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
    ]
  }
}

说明：
	•	content_package_version（v0.2.1 新增）：内容资产包版本号（动态报告引擎的统一入口）
	•	profile_version（保留兼容）：旧口径内容版本号（TypeProfile/文案版本）

⸻

3. 创建一次作答 Attempt（提交答案）

前端在用户完成全部题目后，一次性提交所有答案。
后端负责：
	•	写入 attempts 表
	•	根据答案计算各维度分数
	•	写入 results 表
	•	返回 attempt_id + type_code + 五轴数据

3.1 POST /api/v0.2/attempts

请求体

{
  "scale_code": "MBTI",
  "scale_version": "v0.2",
  "question_count": 144,
  "anon_id": "wxapp:8f1a0c5e-1234-5678-90ab-abcdefabcdef",
  "answers": [
    { "question_id": "MBTI-001", "option_code": "A" },
    { "question_id": "MBTI-002", "option_code": "B" }
  ]
}

字段说明：
	•	scale_code: 固定 "MBTI"（当前版本）
	•	scale_version: 题目版本号
	•	question_count: 本次作答题目总数
	•	anon_id: 匿名用户 ID（前端生成/缓存）
	•	answers: 题目作答列表

服务器端会将部分信息汇总为 answers_summary_json 存入 attempts 表（用于重算/质检）。

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
    "scores_raw": {
      "EI": 12,
      "SN": 8,
      "TF": 10,
      "JP": 14,
      "AT": 6
    },
    "scores_pct": {
      "EI": 78,
      "SN": 62,
      "TF": 71,
      "JP": 85,
      "AT": 58
    },
    "axis_states": {
      "EI": "clear",
      "SN": "moderate",
      "TF": "clear",
      "JP": "strong",
      "AT": "weak"
    },
    "profile_version": "mbti32-v2.5",
    "content_package_version": "MBTI-CN-v0.2",
    "computed_at": "2025-12-15T09:32:00Z"
  }
}

v0.2.1 新增说明：
	•	scores_raw：原始维度分数（保留 v0.2 的能力）
	•	scores_pct：0–100 的百分比（用于强度分档/状态机）
	•	axis_states：每条轴输出一个 state（如 very_weak/weak/moderate/clear/strong/very_strong），前端不参与判断
	•	content_package_version：内容包版本，后续动态报告引擎统一用它装配内容

响应示例（参数错误）

{
  "ok": false,
  "error": "VALIDATION_FAILED",
  "message": "answers must contain 144 items.",
  "data": null
}


⸻

4. 获取一次结果详情（Result + Profile + 动态扩展位）

前端在提交成功后，可使用 attempt_id 拉取结果。
v0.2.1 仍保留 profile（TypeProfile 骨架），并新增 “动态扩展位”，用于将来千人千面组装。

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
      "scores_raw": {
        "EI": 12,
        "SN": 8,
        "TF": 10,
        "JP": 14,
        "AT": 6
      },
      "scores_pct": {
        "EI": 78,
        "SN": 62,
        "TF": 71,
        "JP": 85,
        "AT": 58
      },
      "axis_states": {
        "EI": "clear",
        "SN": "moderate",
        "TF": "clear",
        "JP": "strong",
        "AT": "weak"
      },
      "profile_version": "mbti32-v2.5",
      "content_package_version": "MBTI-CN-v0.2",
      "computed_at": "2025-12-15T09:32:00Z",
      "is_valid": true
    },
    "profile": {
      "type_code": "ENFJ-A",
      "type_name": "主人公型",
      "tagline": "笃定型领路人",
      "rarity": "约 2%（较为少见）",
      "keywords": ["理想主义", "高共情", "号召力强", "有责任感", "自信稳重"],
      "intro": "你像一束方向感清晰的聚光灯……",
      "traits": { "title": "人格特征", "paragraphs": ["……"] },
      "career": { "title": "适合的学习与职业方向", "paragraphs": ["……"] },
      "relationships": { "title": "在人际与亲密关系中的样子", "paragraphs": ["……"] },
      "growth": { "title": "成长建议", "paragraphs": ["……"] }
    },
    "dynamic": {
      "highlights": [
        {
          "axis": "JP",
          "side": "J",
          "state": "strong",
          "cards": [
            { "card_type": "explain", "title": "……", "body": "……" },
            { "card_type": "action", "title": "……", "body": "……" }
          ]
        }
      ],
      "borderline_note": null,
      "sections": {
        "traits": { "cards": [] },
        "career": { "cards": [] },
        "growth": { "cards": [] },
        "relationships": { "cards": [] }
      },
      "layer_profiles": {
        "role_card": null,
        "strategy_card": null,
        "identity_card": null
      },
      "recommended_reads": []
    }
  }
}

v0.2.1 新增说明：
	•	dynamic（新增）：动态报告扩展位（可先返回空数组/空对象，不影响 v0.2 前端）
	•	highlights[]：Top 强度轴的卡片集合（结果页最显眼位置）
	•	borderline_note：若存在 very_weak（如 <55）则输出“灵活/双栖提示卡”
	•	sections.*.cards[]：按区块分发的卡片列表（traits/career/growth/relationships）
	•	layer_profiles.*：Role/Strategy/Identity 三张卡（可先为 null）
	•	recommended_reads[]：内容图谱推荐（可先为空）

响应示例（未找到）

{
  "ok": false,
  "error": "RESULT_NOT_FOUND",
  "message": "Result for given attempt_id not found.",
  "data": null
}


⸻

5. 分享卡数据接口（v0.2.1 新增）

用于前端生成“朋友圈/微信群分享卡”。
该接口只返回分享模板所需字段，不返回整份 profile/report。

5.1 GET /api/v0.2/attempts/{attempt_id}/share

路径参数
	•	attempt_id：作答唯一 ID（UUID）

请求示例

GET /api/v0.2/attempts/b4e1a8f3-0c91-4a25-a57d-abc123456789/share

响应示例（成功）

{
  "ok": true,
  "error": null,
  "message": "success",
  "data": {
    "attempt_id": "b4e1a8f3-0c91-4a25-a57d-abc123456789",
    "share_id": "sh_2d9f7c6c1a0b4e0f",
    "scale_code": "MBTI",
    "scale_version": "v0.2",
    "type_code": "ENFJ-A",
    "type_name": "主人公型",
    "tagline": "笃定型领路人",
    "rarity": "约 2%（较为少见）",
    "keywords": ["理想主义", "高共情", "号召力强", "有责任感", "自信稳重"],
    "short_summary": "……",
    "content_package_version": "MBTI-CN-v0.2"
  }
}

说明：
	•	前端拿到这些字段后自行渲染分享模板并生成图片
	•	前端生成图片成功后应上报事件：share_generate（见 events）

⸻

6. 上报埋点事件（Events）

前端在关键节点调用，用于写入 events 表。
事件类型请参考 docs/fap-v0.2-glossary.md 中 event_code 定义。

6.1 POST /api/v0.2/events

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
    "content_package_version": "MBTI-CN-v0.2",
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

7. 简易统计接口（供后台 / Cron 使用）

v0.2 提供一个只读统计接口，方便后台页面或外部工具拉取核心指标。
与 php artisan fap:weekly-report 命令使用相同口径。

7.1 GET /api/v0.2/stats/summary

请求参数（Query）
	•	days（可选）：统计最近 N 天，默认 7
	•	scale_code（可选）：默认 MBTI
	•	region（可选）：区域过滤，如 CN_MAINLAND
	•	channel（可选）：渠道过滤，如 wechat-ad
	•	content_package_version（可选）：内容包版本过滤，如 MBTI-CN-v0.2（v0.2.1 新增，可选）

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
      { "type_code": "ENFJ-A", "count": 1 }
    ]
  }
}


⸻

8. 安全与限流（简要）

v0.2 可以只做最小安全措施，后续版本再增强。
	•	建议开启：
	•	基于 IP 的简单限流（如 Laravel throttle 中间件）
	•	针对 POST /attempts 和 POST /events 的速率控制
	•	所有接口使用 HTTPS（生产环境）
	•	敏感日志（如 answers_summary_json）仅内部可见，不在错误信息中回显

⸻

9. 兼容性与演进说明

9.1 v0.2.1 的定位
	•	不改变 v0.2 的主路径与主流程：拉题 → 提交 → 出结果 → 埋点
	•	以“字段扩展 + 新增 share 读取接口”的方式，为后续动态报告引擎提供扩展位

9.2 v0.2 → v0.2.1 的关键新增
	•	scores_pct：五轴百分比（0–100）
	•	axis_states：五轴强度 state（用于选卡/语气/弱特质处理）
	•	content_package_version：内容资产包版本（统一入口）
	•	GET /attempts/{attempt_id}/share：分享卡字段只读接口
	•	dynamic：结果接口内的动态扩展位（可先为空）

9.3 未来 v0.3+ 可能新增
	•	批量事件上报
	•	用户登录 / 历史记录查询
	•	更多量表（IQ / 抑郁筛查 / 关系测评等）共用同一套 API 规范
	•	动态报告装配独立接口（若未来需要与结果读取解耦）

