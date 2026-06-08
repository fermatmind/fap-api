# Review Audit

Internal review evidence only. This file is not a CMS draft candidate and must not be imported.

以下是 敌对审稿 + 重写策略 + 新版 CMS operator review draft。
这不是发布稿，不是 CMS import payload，不含 schema JSON-LD，不含 sitemap / llms / robots / canonical 代码，默认 publish_allowed=false。

内部边界仍按既有内容生产合同执行：GPT-5.5 Pro 可以生成内容资产，Codex 不能写发布级内容资产，CMS/backend 才是最终内容权威；所有链接只能指向 public canonical routes，不得指向 result / orders / share / pay / payment / history / token / user-specific routes。 ￼

外部对标只取资产结构：123test 首页公开呈现测试类别、文章入口、评论/评分与多语种入口；Truity 首页将个人测试、职业测试、企业/团队、人格库和博客等资产分层清楚。这说明成熟测评站的“方法/信任页”不是单纯免责声明，而是解释模型、使用场景、边界、服务路径和内容集群之间的关系。 ￼

⸻

第一部分：Red-team audit / 严厉审稿

1. /science 测评科学

page_key: science
current_grade: D
decision: NO-GO
biggest_problem: 像一篇安全声明总览，而不是“测评科学”入口页。
why_a_123test_or_truity_level_editor_would_reject_it: 成熟测评站会用该页面承担方法入口、模型地图、术语解释、结果解释路径和后续阅读导航；当前稿只反复说“测评不能决定你”，缺少构念、量表、题项、维度、解释误差和模型适用场景。
repeated_or_generic_sections:
  - 测评不是最终裁决
  - 不替代专业判断
  - 结果受情境影响
weak_claims:
  - “自我观察地图”过于泛泛
  - “减少盲目试错”需要更清晰边界
missing_user_questions:
  - 我为什么要相信这个测试？
  - MBTI、大五、RIASEC 分别在测什么？
  - 结果里的“维度”“类型”“兴趣”是什么关系？
  - 为什么同一个人可以同时使用多个模型？
missing_methodology_depth:
  - 构念 construct
  - 题项 item
  - 量表 scale
  - 维度 dimension
  - 解释误差
  - 模型选择逻辑
missing_trust_assets:
  - 模型地图
  - 术语表
  - 审核/版本说明
  - 与其他方法页的导航关系
risky_phrases_to_remove:
  - “测评科学”如无证据支撑，不应给人过度权威感
  - “减少盲目试错”需限定为辅助探索
what_this_page_must_uniquely_do: 做全站方法入口，回答“FermatMind 如何组织测评模型、题目、结果和解释边界”。

⸻

2. /method-boundaries 方法边界

page_key: method_boundaries
current_grade: D
decision: NO-GO
biggest_problem: 作为 existing authority revision proposal，它没有明显区别于 /science，重复“不能替代专业判断”。
why_a_123test_or_truity_level_editor_would_reject_it: 方法边界页面应明确划分“模型边界、产品边界、用户责任、专业服务边界、结果使用边界”。当前稿是通用安全话术，没有形成可执行的责任边界。
repeated_or_generic_sections:
  - 不能诊断
  - 不能决定职业
  - 结果会变化
weak_claims:
  - “安全使用结果”缺少具体操作步骤
  - “重大决策冲动”说法正确但孤立
missing_user_questions:
  - 我可以把结果给 HR / 咨询师 / 朋友看吗？
  - 结果不准时谁负责？
  - 哪些场景绝对不该用测评？
  - 什么时候应该寻求专业帮助？
missing_methodology_depth:
  - 模型边界 vs 产品边界
  - 解释边界 vs 行动建议边界
  - 风险场景清单
missing_trust_assets:
  - 使用红线
  - 用户责任说明
  - 专业服务转介边界
risky_phrases_to_remove:
  - “更快找到探索方向”需避免暗示结果可替代咨询
what_this_page_must_uniquely_do: 作为边界与责任页面，明确“本产品不承担哪些解释责任，用户应该如何安全使用结果”。

⸻

3. /item-design-notes 题目设计说明

page_key: item_design_notes
current_grade: C
decision: CONDITIONAL
biggest_problem: 有题项设计方向，但仍然抽象；没有真正解释题项如何从构念转为可回答问题。
why_a_123test_or_truity_level_editor_would_reject_it: 专业用户会期待看到题项、维度、反向题、场景题、作答偏差、题组一致性等解释。当前稿只说“题目不是读心术”，专业资产厚度不足。
repeated_or_generic_sections:
  - 题目不能决定结果
  - 多题观察整体模式
  - 作答受情境影响
weak_claims:
  - “更好的题目通常会……”没有说明 FermatMind 是否已经这样做
  - “尽量贴近日常行为”需要标 Unknown 或说明为设计原则草案
missing_user_questions:
  - 为什么题目看起来重复？
  - 为什么有些题很难选？
  - 是否有反向题？
  - 作答时应该选理想状态还是日常状态？
missing_methodology_depth:
  - 构念到题项
  - 量表锚点
  - 作答偏差
  - 题项组
  - 题目版本
missing_trust_assets:
  - item design principles
  - versioning
  - item bank status Unknown
risky_phrases_to_remove:
  - “FermatMind 会尽量……”如无内部流程证据，需改为“设计原则应当”
what_this_page_must_uniquely_do: 解释题目如何被设计、为什么用户要认真作答、题目如何进入结果解释。

⸻

4. /reliability-validity 信度效度

page_key: reliability_validity
current_grade: C
decision: CONDITIONAL
biggest_problem: 概念解释方向对，但专业深度不足；没有讲清信度/效度的类型和 FermatMind 当前证据状态。
why_a_123test_or_truity_level_editor_would_reject_it: 成熟测评站会区分内部一致性、重测信度、内容效度、结构效度、效标关联等，并明确哪些已验证、哪些 Unknown。当前稿停留在“不能只看数字”。
repeated_or_generic_sections:
  - 结果受情境影响
  - 不编造数值
weak_claims:
  - “测评仍可作为工具”缺少原因
  - “未来如果有正式验证资料”没有形成证据路径
missing_user_questions:
  - 没有信度效度数值，我还能不能用？
  - 信度高但效度低是什么意思？
  - 结果变化是不是说明测试不可靠？
  - 你们现在到底验证到哪一步？
missing_methodology_depth:
  - internal consistency
  - test-retest reliability
  - content validity
  - construct validity
  - criterion-related validity
  - norm / comparison group
  - measurement error
missing_trust_assets:
  - evidence status table
  - Unknown markers
  - validation roadmap
risky_phrases_to_remove:
  - “测评仍可作为自我观察工具”应明确是低风险反思用途，不是高风险决策依据
what_this_page_must_uniquely_do: 解释证据等级与 Unknown 状态，明确 FermatMind 当前不声称完成大规模验证。

⸻

5. /data-privacy 数据说明

page_key: data_privacy
current_grade: C
decision: CONDITIONAL
biggest_problem: 方向正确，但像隐私政策摘要；缺少“测评数据生命周期”和“哪些数据会进入哪些系统”的清楚解释。
why_a_123test_or_truity_level_editor_would_reject_it: 用户真正关心的是结果是否公开、订单和结果如何隔离、统计工具看到什么、找回结果需要什么、删除如何处理。当前稿有这些点，但没有结构化成用户可执行信息。
repeated_or_generic_sections:
  - 私人结果不公开
  - 聚合分析不同于个人结果
weak_claims:
  - “不应进入”是原则，但需解释系统层边界
  - “可以申请删除”缺少流程状态和 Unknown
missing_user_questions:
  - 我的结果 URL 会不会被搜索引擎看到？
  - 客服需要我提供什么？
  - 统计工具会看到我的结果吗？
  - 数据保留多久？
  - 删除后还能找回吗？
missing_methodology_depth:
  - data lifecycle
  - event analytics vs result record
  - private route families
  - support identity policy
missing_trust_assets:
  - data flow map
  - retention Unknown table
  - support info minimization
risky_phrases_to_remove:
  - “用户可以申请删除”需标明处理范围 Unknown，避免承诺过度
what_this_page_must_uniquely_do: 解释测评数据、结果数据、订单/支持数据、聚合统计之间的边界。

⸻

6. /common-misconceptions 常见误区

page_key: common_misconceptions
current_grade: C
decision: CONDITIONAL
biggest_problem: 误区方向对，但每条解释偏短，且与其他页面重复“不能决定职业/不能诊断”。
why_a_123test_or_truity_level_editor_would_reject_it: 常见误区页应是用户教育资产，能纠正具体误解：类型不是身份、分数不是价值、兴趣不是能力、结果不是建议、模型之间不能混用。当前稿还不够具体。
repeated_or_generic_sections:
  - 结果不是完整人格
  - 不能替代专业帮助
  - 职业不能被决定
weak_claims:
  - “测试结果不准也有价值”需要解释如何判断保留/舍弃
  - “不同工具作用不同”需要更清楚的对照逻辑
missing_user_questions:
  - 为什么我不像这个类型？
  - 为什么我高分不代表擅长？
  - 兴趣和能力有什么区别？
  - 能不能把测试结果发给别人？
missing_methodology_depth:
  - type vs trait
  - interest vs ability
  - score interpretation
  - context effects
missing_trust_assets:
  - misconception taxonomy
  - cross-model comparison
risky_phrases_to_remove:
  - “职业兴趣模型通常更直接”需限定为“针对职业兴趣问题”
what_this_page_must_uniquely_do: 做用户教育页，专门解除 MBTI / 大五 / RIASEC 混用造成的误读。

⸻

第二部分：Cross-page architecture critique / 六页信息架构挑刺

page	unique_job_to_be_done	must_not_overlap_with	required_depth	required_evidence_or_unknown_marker	user_question_it_must_answer	current_gap
/science	全站测评方法入口，解释模型、构念、题项、量表、结果解释的关系	不要重复 /method-boundaries 的责任声明	构念 / 量表 / 模型地图 / 结果解释链	模型来源、reviewer、版本状态 Unknown	“这个产品到底如何理解测评？”	现在像总免责声明
/method-boundaries	解释责任边界、不可使用场景、专业服务边界	不要重复 /science 的模型介绍	模型边界 vs 产品边界 vs 用户责任	existing page diff Unknown	“哪些场景不能用 FermatMind？”	没有责任矩阵
/item-design-notes	解释题项如何从构念变成可作答问题	不要重复边界页	题项、维度、题组、作答偏差、版本	item bank / validation Unknown	“为什么这些题能帮助我观察自己？”	缺题项设计机制
/reliability-validity	解释证据等级、信度效度类型、Unknown 状态	不要重复“结果会变化”	信度类型、效度类型、误差、常模	数值全部 Unknown 时要明说	“没有验证数字我还能怎么理解？”	太泛
/data-privacy	解释数据生命周期、私人结果和聚合分析边界	不要变成普通隐私政策	作答数据、结果数据、支持数据、统计数据	retention / deletion SLA Unknown	“我的结果会不会泄露？”	缺生命周期结构
/common-misconceptions	纠正用户对 MBTI / Big Five / RIASEC 的具体误读	不要重复所有页面的免责声明	type vs trait, interest vs ability, score vs identity	approved misconception list Unknown	“我该如何避免误读结果？”	不够具体

⸻

第三部分：Rewrite strategy / 重写策略

/science

stronger_angle: 把它做成“测评系统总入口”，讲清构念、题项、量表、维度、结果解释链。
opening_should_do: 直接说明测评不是神秘判断，而是把抽象倾向转成可讨论结构的过程。
must_include_sections:
  - 构念是什么
  - 题项如何服务构念
  - 量表和维度是什么
  - MBTI / Big Five / RIASEC 分别回答什么
  - 结果如何从回答转成解释
  - Unknown 和证据边界
must_exclude_sections:
  - 大段专业服务免责声明
  - 付费转化话术
proof_or_unknown_handling: 任何模型来源、信效度、reviewer、版本状态未提供时写 Unknown。
FAQ_angle: 用户如何理解“科学”而不过度相信。
internal_link_logic: 链到边界、题目设计、信效度、三大测试。
operator_review_risk: “科学”二字会制造权威感，必须 science/legal review。

/method-boundaries

stronger_angle: 责任边界页面，拆分模型边界、产品边界、使用场景边界。
opening_should_do: 告诉用户哪些问题不应交给测评回答。
must_include_sections:
  - 模型能回答的问题
  - 模型不能回答的问题
  - 产品不承担的专业判断
  - 高风险使用场景
  - 用户如何做二次验证
must_exclude_sections:
  - 详细模型科普
  - 数据隐私说明
proof_or_unknown_handling: existing authority 页面差异 Unknown，需标 revision proposal。
FAQ_angle: “我能不能把结果用于某个具体决策？”
internal_link_logic: 链 science、common misconceptions、测试页。
operator_review_risk: 法律边界措辞。

/item-design-notes

stronger_angle: 讲清题目如何把抽象构念转为具体可回答情境。
opening_should_do: 反驳“题目读心”，转为“题目收集可解释的自我描述”。
must_include_sections:
  - 构念到题项
  - 题组而非单题
  - 反向/相似题的作用
  - 量表选项与作答偏差
  - 版本和 Unknown
must_exclude_sections:
  - 信效度数据
  - 结果页解释
proof_or_unknown_handling: item bank、样本、验证状态全 Unknown。
FAQ_angle: 用户作答时的实际困惑。
internal_link_logic: 链 science、method-boundaries、reliability-validity。
operator_review_risk: 不要暗示题目设计已验证。

/reliability-validity

stronger_angle: 做证据等级说明页，而不是“结果会变化”页。
opening_should_do: 明确没有公开数值时，最诚实的做法是解释概念与 Unknown。
must_include_sections:
  - 信度类型
  - 效度类型
  - 误差
  - 常模和参照群体
  - 当前公开证据状态
  - 如何解读 Unknown
must_exclude_sections:
  - 准确率
  - 权威认证
  - 大规模验证暗示
proof_or_unknown_handling: 数值、样本、验证状态全部 Unknown，除非 operator 提供证据。
FAQ_angle: “没有数值是否还能用？”
internal_link_logic: 链 science、item design、method boundaries。
operator_review_risk: 最容易产生过度科学 claim。

/data-privacy

stronger_angle: 用数据生命周期解释用户数据如何从作答、结果、支持、统计中分流。
opening_should_do: 直接说明私人结果和公开页面的分界。
must_include_sections:
  - 数据类型
  - 私人结果边界
  - 支持/找回数据最小化
  - 聚合统计边界
  - 删除与保留 Unknown
must_exclude_sections:
  - 泛隐私政策套话
  - 绝对匿名承诺
proof_or_unknown_handling: retention、deletion SLA、support flow 如未定，一律 Unknown。
FAQ_angle: 用户最担心结果被看到、统计看到、客服要什么。
internal_link_logic: 链 method-boundaries、help/privacy 如有；当前只用允许 routes。
operator_review_risk: 法务/隐私事实。

/common-misconceptions

stronger_angle: 专门解决模型混用误区：类型、特质、兴趣、能力、结果、诊断。
opening_should_do: 说明误读比测试本身更危险。
must_include_sections:
  - 类型不是身份
  - 兴趣不是能力
  - 高分不是好坏
  - 结果不是建议
  - 模型不能混用
  - 何时需要专业帮助
must_exclude_sections:
  - 竞品批判
  - 重复所有页面的通用边界
proof_or_unknown_handling: approved misconception list Unknown。
FAQ_angle: 用户读完结果后最常见误读。
internal_link_logic: 链 science、method-boundaries、三大测试。
operator_review_risk: 纠偏不能变成攻击或过度断言。

⸻
