# Operator Review Package

This file is not a CMS draft candidate. It summarizes review notes, internal link suggestions, checklist items, and final QA for the operator.

## SCIENCE-HUB-CONTENT-01 / /science

### Claim review notes

safe_claims_used:
  - 测评用于结构化观察
  - 构念、题项、量表、维度的关系说明
  - 不同模型回答不同问题
  - 结果用于提出问题和继续验证
borderline_claims_needing_review:
  - “测评科学”标题本身的权威感
  - “模型规则整理”是否需要更具体的技术说明
forbidden_claims_removed:
  - 医学或心理健康评估
  - 职业保证
  - 准确率
  - 未经证据支持的权威背书
evidence_needed_before_publish:
  - 模型来源
  - 题库版本
  - reviewer 信息
  - 是否有公开信度效度资料
Unknown_fields:
  - reviewer: Unknown
  - public reliability/validity numbers: Unknown
  - item bank version: Unknown

### Internal link suggestions

internal_links:
  - route: /method-boundaries
    purpose: 解释使用边界
    status: allowed
  - route: /item-design-notes
    purpose: 解释题目设计
    status: allowed
  - route: /reliability-validity
    purpose: 解释信度效度
    status: allowed
  - route: /tests/holland-career-interest-test-riasec
    purpose: 职业兴趣测评入口
    status: allowed

### Operator review checklist

operator_review_checklist:
  - claim boundary
  - route validity
  - visible FAQ
  - no private URL
  - no diagnostic claim
  - no career guarantee
  - no official endorsement
  - no competitor imitation
  - no DailyGiving amplification
  - publish_allowed remains false until approval

⸻

## METHOD-BOUNDARY-CONTENT-01 / /method-boundaries

### Claim review notes

safe_claims_used:
  - 模型只能解释特定构念范围
  - 产品不替代专业服务
  - 结果可转化为验证问题
borderline_claims_needing_review:
  - 公司/团队使用边界是否需要法律补充
  - 危机状态提示是否需要指向专业资源
forbidden_claims_removed:
  - 测评决定职业
  - 测评判断能力
  - 测评进行医学或心理健康评估
evidence_needed_before_publish:
  - existing authority 页面 diff
  - 法务对专业服务边界的审核
Unknown_fields:
  - existing page paragraph mapping: Unknown

### Internal link suggestions

internal_links:
  - route: /science
    purpose: 测评系统总览
    status: allowed
  - route: /item-design-notes
    purpose: 题目设计说明
    status: allowed
  - route: /reliability-validity
    purpose: 证据与误差说明
    status: allowed

### Operator review checklist

operator_review_checklist:
  - claim boundary
  - route validity
  - visible FAQ
  - existing authority revision only
  - no private URL
  - no diagnostic claim
  - no career guarantee
  - no official endorsement
  - no competitor imitation
  - no DailyGiving amplification
  - publish_allowed remains false until approval

⸻

## ITEM-DESIGN-CONTENT-01 / /item-design-notes

### Claim review notes

safe_claims_used:
  - 题项用于观察构念侧面
  - 单题不能单独决定结果
  - 作答偏差存在
  - item bank 状态 Unknown
borderline_claims_needing_review:
  - “相似题”“反向题”的解释是否符合实际题库
forbidden_claims_removed:
  - 题目能读心
  - 题目证明能力
  - 题目判断心理健康状态
evidence_needed_before_publish:
  - 题库版本
  - 题项开发流程
  - 是否使用反向题
  - 是否有题组/维度映射表
Unknown_fields:
  - item bank version: Unknown
  - reverse item usage: Unknown
  - validation sample: Unknown

### Internal link suggestions

internal_links:
  - route: /science
    purpose: 测评系统总览
    status: allowed
  - route: /method-boundaries
    purpose: 使用边界说明
    status: allowed
  - route: /reliability-validity
    purpose: 信度效度说明
    status: allowed

### Operator review checklist

operator_review_checklist:
  - claim boundary
  - route validity
  - visible FAQ
  - item bank Unknowns preserved
  - no private URL
  - no diagnostic claim
  - no career guarantee
  - no official endorsement
  - no competitor imitation
  - no DailyGiving amplification
  - publish_allowed remains false until approval

⸻

## RELIABILITY-VALIDITY-CONTENT-01 / /reliability-validity

### Claim review notes

safe_claims_used:
  - 信度和效度分开解释
  - 当前公开说明中暂不提供具体数值
  - 常模和样本 Unknown
  - 误差需要承认
borderline_claims_needing_review:
  - 是否允许列举信度/效度类型
  - 是否需要删除“结构效度”等术语以降低误读
forbidden_claims_removed:
  - 绝对准确
  - 已大规模验证
  - 未经证据支持的权威背书
  - 预测成功
evidence_needed_before_publish:
  - reliability metrics
  - validity evidence
  - sample size
  - norm group
  - language/version scope
Unknown_fields:
  - reliability numeric values: Unknown
  - validity numeric values: Unknown
  - sample size: Unknown
  - norm group: Unknown

### Internal link suggestions

internal_links:
  - route: /science
    purpose: 测评科学总览
    status: allowed
  - route: /item-design-notes
    purpose: 题目设计说明
    status: allowed
  - route: /method-boundaries
    purpose: 使用边界说明
    status: allowed

### Operator review checklist

operator_review_checklist:
  - claim boundary
  - route validity
  - visible FAQ
  - Unknown values preserved
  - no fabricated reliability data
  - no fabricated validity data
  - no private URL
  - no diagnostic claim
  - no career guarantee
  - no official endorsement
  - no competitor imitation
  - no DailyGiving amplification
  - publish_allowed remains false until approval

⸻

## DATA-NOTES-CONTENT-01 / /data-privacy

### Claim review notes

safe_claims_used:
  - 作答数据和结果数据具有个人性
  - 私人结果不应进入公开发现面
  - 聚合统计不等于个人结果公开
  - retention/deletion Unknown preserved
borderline_claims_needing_review:
  - 用户可以申请删除数据的范围
  - 支持流程字段
forbidden_claims_removed:
  - 绝对匿名
  - 立即删除
  - 永不保留
  - 绝对安全
evidence_needed_before_publish:
  - retention period
  - deletion workflow
  - support contact authority
  - analytics private URL handling
Unknown_fields:
  - retention period: Unknown
  - deletion SLA: Unknown
  - account deletion status: Unknown
  - support form status: Unknown

### Internal link suggestions

internal_links:
  - route: /method-boundaries
    purpose: 使用边界说明
    status: allowed
  - route: /science
    purpose: 测评科学总览
    status: allowed
  - route: /common-misconceptions
    purpose: 解释常见误读
    status: allowed

### Operator review checklist

operator_review_checklist:
  - claim boundary
  - route validity
  - visible FAQ
  - privacy/legal review
  - no private URL
  - no diagnostic claim
  - no career guarantee
  - no official endorsement
  - no competitor imitation
  - no DailyGiving amplification
  - publish_allowed remains false until approval

⸻

## MISCONCEPTIONS-CONTENT-01 / /common-misconceptions

### Claim review notes

safe_claims_used:
  - 类型不是身份
  - 分数不是价值判断
  - 兴趣不是能力
  - 结果不是行动命令
  - 不同模型不能混用
borderline_claims_needing_review:
  - MBTI、Big Five、RIASEC 对比是否需要来源
  - “不同模型不是同一种测评”措辞是否足够精确
forbidden_claims_removed:
  - 职业保证
  - 医学或心理健康评估
  - 竞品攻击
  - 模型优劣绝对判断
evidence_needed_before_publish:
  - 模型定义来源
  - approved misconception list
  - science reviewer 校对
Unknown_fields:
  - approved misconception list: Unknown

### Internal link suggestions

internal_links:
  - route: /science
    purpose: 测评科学总览
    status: allowed
  - route: /method-boundaries
    purpose: 方法边界说明
    status: allowed
  - route: /reliability-validity
    purpose: 信度效度说明
    status: allowed
  - route: /tests/mbti-personality-test-16-personality-types
    purpose: MBTI 测评入口
    status: allowed
  - route: /tests/big-five-personality-test
    purpose: 大五测评入口
    status: allowed
  - route: /tests/holland-career-interest-test-riasec
    purpose: RIASEC 测评入口
    status: allowed

### Operator review checklist

operator_review_checklist:
  - claim boundary
  - route validity
  - visible FAQ
  - no private URL
  - no diagnostic claim
  - no career guarantee
  - no official endorsement
  - no competitor imitation
  - no DailyGiving amplification
  - publish_allowed remains false until approval

⸻

## Final QA table

第五部分：Final QA table

page_key	old_grade	revised_grade	decision	main_remaining_risk	evidence_needed	ready_for_operator_review	reason
science	D	B	CONDITIONAL	“科学”措辞仍需避免过度权威感	模型来源、reviewer、版本状态	conditional	已从泛免责声明改成方法入口，但仍需 science review
method_boundaries	D	B	CONDITIONAL	existing authority revision 需逐段 diff	现有页面 diff、法律审核	conditional	已区分模型边界、产品边界、用户责任
item_design_notes	C	B	CONDITIONAL	item bank / 题项设计事实 Unknown	题库版本、反向题、题组映射	conditional	已加入题项、构念、作答偏差，但不能声称已验证
reliability_validity	C	B	CONDITIONAL	数值全部 Unknown，易被误读为证据不足	信度、效度、样本、常模	conditional	已讲清证据类型和 Unknown 处理
data_privacy	C	B	CONDITIONAL	隐私/删除事实需要法务确认	retention、删除 SLA、支持流程	conditional	已从隐私摘要升级成数据生命周期说明
common_misconceptions	C	B	CONDITIONAL	模型对比需要来源确认	模型定义来源、approved misconception list	conditional	已具体拆分类型/特质/兴趣/能力误区

This is a CMS operator review draft only. It is not approved for publish, import, sitemap, llms, footer, search submission, or social distribution.
