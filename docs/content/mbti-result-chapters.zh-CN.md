# 中文 MBTI 结果页三章导语与 FAQ

## 编辑入口与发布范围

`backend/content_assets/personality_public/mbti_result_chapters.zh-CN.v1.json` 是这四个位置的编辑源，包含 32 个完整类型。每条记录提供 career、growth、relationships 各两段，以及四个完整问答。A/T 共用适用的四字母偏好描述，第二段分别处理压力和反馈中的不同情境；FAQ 的共通事实不人为写成互相矛盾的类型差异。

`MbtiZhResultContentPackage` 把这四个字段编入已有的结果包，数据库 `personality_profile_variant_clone_contents` 继续作为运行时权威。读取请求不加载作者文件、不生成文字。首屏简介、五维解释、特质总述、详细卡片、媒体槽位和英文内容不由此文件管理。

修改后重新编译包，并同步 `mbti_zh_result_authority_release.v1.json` 与现有部署工作流中的精确包标识。原有 draft → promotion → database/public readback → rollback 链路负责发布。已发布记录的非目标正文字段若有独立修改，预检拒绝覆盖；发布期间发生的编辑由 pre-state hash 检测。无需新表、路由或前端契约变更。

## 内容标准

- 参考用户提供的 16P ENFP 中文结果页三个导语块，其长度分别为 318、324、308 字（含标点、不含空白），每块两段。当前稿保持每章 300–370 字；长度是阅读规格，不是科学质量的证明。
- 第一段说明可观察的工作或相处偏好，第二段展开具体困难、情境限制与可尝试的做法。不用职业名单、天赋赞美或抽象人格称号代替解释。
- 32 个类型均有完整资产，但不制造 A/T 在兴趣、智力、职业胜任力上的额外差异。接近计分中点时不作强解释；百分比不是能力分、诊断或人群排名。
- 四个问答分别涵盖结果解读、职业探索、成长实践和关系协商。每个回答说明理由、具体用法和必要限制，不只给一句结论。
- 实践建议是可检验的日常尝试，不宣称已针对本测评或某个类型验证疗效。A/T 为产品扩展，不能借用官方 MBTI 的研究为它背书。

第一稿完成后，第二轮改写了 48 段的情境、例子与重复开头，并复核 FAQ 的类型误解、A/T 限制和中点解释。全文检查保留 A/T 间合理共享的内容，检查不同四字母类型间的整段重复、套话、绝对能力推断，以及正文与五维描述的方向冲突。自动计数和相似度检查只帮助定位问题，不等同于科学验证或专业审稿。

## 参考范围

- [用户指定的 16P ENFP 结果页](https://www.16personalities.com/ch/%E7%BB%93%E6%9E%9C/enfp-t/x/3wfmvks4j)：只参考块结构、阅读长度和主题推进，不复用原文或插图。
- [Myers & Briggs Foundation：All Types Are Valuable](https://www.myersbriggs.org/my-mbti-personality-type/all-types-are-valuable/)：类型价值与差异的使用边界。
- [16Personalities：Our Framework](https://www.16personalities.com/articles/our-theory)：其产品框架的自我说明，不作为 FermatMind 量表效度证据。
