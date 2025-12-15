FAP v0.2 领域词典（Glossary）

FAP = Fermat Assessment Platform
适用范围：v0.2 版本（MBTI 主流程）所有后端 / 前端 / 数据分析文档 & 代码。

⸻

0. 命名总原则
	•	机器名用英文+下划线：scale_code、attempt_id、event_code
	•	对外展示可用中英文：页面文案、运营文档
	•	能复用的概念 → 统一写入本词典：所有新文档出现的新词，优先来这里登记。

⸻

1. 平台与环境（Platform & Environment）

1.1 Fermat Assessment Platform（FAP）
	•	中文：费马测试平台 / 费马测评平台
	•	说明：承载所有在线心理测验与量表的技术平台。
	•	典型用法：
	•	系统概览：Fermat Assessment Platform v0.2
	•	域名：fermatmind.com / 微信小程序「费马测试」。

1.2 APP_ENV
	•	类型：string（环境枚举）
	•	枚举值：
	•	local：本地开发环境（Mac + PHP 内置服务）
	•	staging（预留）：测试 / 预发布环境
	•	production：正式线上环境
	•	说明：Laravel 环境标识，用于区分 DB / Redis / 日志等配置。

1.3 APP_REGION
	•	类型：string
	•	示例值：
	•	CN_MAINLAND：中国大陆
	•	GLOBAL：全球通用（预留）
	•	说明：宏观地域标识，用于数据合规与报告口径。

1.4 APP_LOCALE
	•	类型：string（IETF 语言标签）
	•	示例值：
	•	zh-CN：简体中文
	•	en-US：美式英语（预留）
	•	说明：前端展示语言 & 文案版本的主开关。

⸻

2. 用户与身份（User & Identity）

2.1 用户（User）
	•	模型：users 表 / App\Models\User（预留）
	•	说明：注册或绑定账号的真实用户，将来用于多次测评归集到同一人。

2.2 匿名标识（anon_id）
	•	字段名：anon_id
	•	类型：string(64)
	•	说明：
	•	前端生成的匿名用户 ID（如 UUIDv4 或 小程序 openid 经过映射）。
	•	未登录时，用于关联同一设备 / 同一小程序用户的多次作答与事件。
	•	示例："anon_id": "wxapp:8f1a0c5e-..."

2.3 user_id
	•	字段名：user_id（nullable）
	•	说明：当用户登录 / 绑定账号之后，attempts / results / events 可填入 user_id 做长期追踪；未登录时为 null。

⸻

3. 量表与测评（Scales & Assessments）

3.1 量表（Scale）
	•	字段名：scale_code
	•	类型：string(32)
	•	说明：代表一套评估工具的「代码名」，贯穿业务和技术。
	•	示例：
	•	MBTI：MBTI 人格测验（费马 v2.5）
	•	IQ_BASIC：基础 IQ 测试（预留）
	•	DEPRESSION_PHQ9：抑郁筛查 PHQ-9（预留）

3.2 量表版本（scale_version）
	•	字段名：scale_version
	•	类型：string(16)
	•	示例："v0.2"、"v1.0"
	•	说明：平台内部用的技术版本号，用于题目结构 / 评分规则升级时的区分。

3.3 profile_version
	•	字段名：profile_version
	•	类型：string(32)
	•	说明：
	•	与内容侧挂钩的「解释文案版本号」，例如 mbti32-v2.5。
	•	同一个测验升级文案/排版时，可以改 profile_version，方便回溯旧报告。

3.4 question_count
	•	字段名：question_count
	•	类型：int
	•	说明：当前量表本次作答实际题目数（如 144 题 MBTI）。

3.5 题目（Question）

响应结构（简化示例）：

```jsonc
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
字段含义：
	•	question_id：题目唯一 ID（<SCALE>-<流水号>），如 MBTI-001
	•	order：本次问卷中的展示顺序
	•	dimension：对应的评分维度（如 EI / SN 等）
	•	text：题目正文
	•	options：选项列表（code + 文案）

⸻

3.6 维度（Dimension）

MBTI 主维度：
	•	EI：外向 – 内向（Extraversion / Introversion）
	•	SN：感觉 – 直觉（Sensing / iNtuition）
	•	TF：思考 – 情感（Thinking / Feeling）
	•	JP：判断 – 感知（Judging / Perceiving）

附加维度：
	•	AT：自信 – 敏感（Assertive / Turbulent）
	•	用于生成 -A 或 -T 的后缀，即 ENFJ-A / INTP-T 等费马版类型。

⸻

4. 作答与结果（Attempts & Results）

4.1 Attempt（一次作答）
	•	表：attempts
	•	模型：App\Models\Attempt

字段关键点：
	•	id：attempt_id（UUID）
	•	anon_id / user_id
	•	scale_code / scale_version
	•	question_count
	•	answers_summary_json：题目作答概要（可供后续重算 / 质检）
	•	client_platform / client_version / channel / referrer
	•	started_at / submitted_at：作答起止时间

4.2 Result（一次结果）
	•	表：results
	•	模型：App\Models\Result

字段关键点：
	•	id：result_id（UUID）
	•	attempt_id（外键）
	•	scale_code / scale_version
	•	type_code：如 ENFJ-A、INTP-T
	•	scores_json：各维度分数字典，如
{ "EI": 12, "SN": 8, "TF": 10, "JP": 14, "AT": 6 }
	•	profile_version：使用的文案版本
	•	is_valid：是否为有效结果（防止异常数据）
	•	computed_at：结果计算完成时间

4.3 type_code（人格类型代码）
	•	示例：ENFJ-A、ISTP-T
	•	结构：四字母主类型 + - + A/T
	•	说明：
	•	前四位为 MBTI 经典 16 型；
	•	末位 A/T 为费马版「自信 / 敏感」倾向（来自 AT 维度）。

⸻

5. 客户端与渠道（Client & Channel）

5.1 client_platform
	•	字段名：client_platform
	•	类型：string(32)

枚举建议：
	•	wechat-miniprogram：微信小程序
	•	web-h5：移动 H5 网页
	•	web-desktop：桌面网页
	•	other：其他 / 待定

5.2 client_version
	•	字段名：client_version
	•	类型：string(32)
	•	说明：前端版本号，例如小程序提交版本号、Web 构建版本号；用于排查版本差异问题。

5.3 channel
	•	字段名：channel
	•	类型：string(32)
	•	说明：推广渠道 / 流量来源。

示例值：
	•	dev：开发调试
	•	organic：自然流量
	•	wechat-ad：微信广告
	•	pdd：拼多多
	•	bilibili / xiaohongshu / douyin 等

5.4 referrer
	•	字段名：referrer
	•	类型：string(255)
	•	说明：上一个页面来源 / 落地页地址，可为空。

⸻

6. 事件与埋点（Events & Tracking）

6.1 Event（事件）
	•	表：events
	•	模型：App\Models\Event

核心字段：
	•	id：事件 ID（可为自增或 UUID）
	•	event_code：事件类型（见下文）
	•	anon_id / user_id
	•	scale_code / scale_version（若有关）
	•	attempt_id（若有关）
	•	channel / region / locale
	•	client_platform / client_version
	•	occurred_at：事件发生时间
	•	meta_json：扩展字段（如 question_count、分享卡类型等）

6.2 event_code（事件类型代码）

目前 v0.2 规划的 核心 5 类：
	1.	scale_view
	•	定义：量表介绍页被浏览一次。
	•	触发时机：用户打开「测试介绍 / 开始页面」。
	2.	test_start
	•	定义：用户实际点击「开始测试」。
	•	触发时机：开始答题第一页真正加载时。
	3.	test_submit
	•	定义：一次作答完成并提交到后端。
	•	触发时机：POST /api/v0.2/attempts 成功写入 attempts + results 时。
	4.	result_view
	•	定义：结果页面被成功展示。
	•	触发时机：前端拿到 GET /api/v0.2/attempts/{id}/result 成功响应，并渲染结果页。
	5.	share_generate
	•	定义：生成了一个可分享的结果图片 / 身份证卡片。
	•	触发时机：前端完成图片合成或调用云函数生成分享图后。

其他预留 event_code：login、register、payment_success、error_occurred 等，可在后续版本扩展。

⸻

7. 指标与分析（Metrics & Analytics）

以下是 v0.2 期固定的 8 个核心指标，与 D1 周报对应。
	1.	scale_view（量表页曝光数）
	•	计算：events 中 event_code = 'scale_view' 的条数。
	2.	test_start（开始作答数）
	•	计算：events 中 event_code = 'test_start' 的条数。
	3.	test_submit（完成提交数）
	•	计算：events 中 event_code = 'test_submit' 的条数。
	4.	result_view（结果页查看数）
	•	计算：events 中 event_code = 'result_view' 的条数。
	5.	share_generate（分享卡生成数）
	•	计算：events 中 event_code = 'share_generate' 的条数。
	6.	TOTAL events（上述 5 类事件总和）
	•	计算：指标 1–5 的数值之和。
	7.	Unique submit anon_ids（提交人数）
	•	计算：在给定时间范围内，events 中 event_code = 'test_submit' 的 distinct anon_id 数量。
	8.	type_code 分布（人格类型占比）
	•	来源：results 表中 type_code 按数量聚合，可配合 profile_version 作为报表切片。

⸻

8. 错误与状态（Status & Errors）

8.1 API 通用响应字段
	•	ok：布尔，true / false
	•	error：错误码字符串（如 RESULT_NOT_FOUND）
	•	message：简要错误信息（便于排查）
	•	data：成功时的业务数据（视接口而定）

8.2 常见错误码（v0.2）
	•	RESULT_NOT_FOUND：根据给定 attempt_id 未找到结果
	•	VALIDATION_FAILED：请求参数校验未通过（由 Laravel validation 负责）
	•	INTERNAL_ERROR：未捕获异常（仅在必要时对外暴露）

⸻

9. 命名规范速查（Naming Conventions）

9.1 ID 规范
	•	attempt_id / result_id：使用 UUIDv4，字符串形式
	•	question_id：<SCALE>-<3位或4位序号>，如 MBTI-001、MBTI-144
	•	event_code：统一小写 + 下划线，如 scale_view、test_start

9.2 JSON 字段命名
	•	一律采用 snake_case：
	•	scale_code, scale_version, client_platform, profile_version 等
	•	布尔字段前缀推荐使用 is_：
	•	is_valid, is_paid 等

⸻

10. 后续维护约定（Maintenance）
	1.	新增概念：
先在本词典增加「英文字段名 + 中文解释 + 示例」，再在代码 / 文档里使用。
	2.	修改口径：
如某指标计算方式调整，必须在本文件同步更新，并标注版本变更（如 v0.3 之后生效）。
	3.	对外协作：
对接设计 / 前端 / 运营时，本词典作为唯一权威解释来源。