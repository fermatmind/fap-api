---
name: fermatmind-career-compare-pseo
description: Extract source-bound inputs, write or revise, and validate FermatMind fixed Career comparison drafts for named pairs in zh-CN and US English. Use for the approved #7 pilot and supplementary Table A draft queue.
---

# 费马固定职业对比候选

按用户指定的职业对和语言执行。当前支持已审的电工样板和清洗后补充表 A；表 B 属于数据复核。公开路由、内容发布和动态 C1 由对应产品任务接入。

## 抽数与审稿

1. 以实际路径提供 #7 Git 对象、清洗后表 A、同快照译名映射及 Career Current 包。两个 slug 必须各过五门槛，且在快照有显式关系。用 `scripts/extract_pair_context.py` 提取每个 pair/locale；参数以 `--help` 为准，输出写到本批目录。样板内置登记，供其他 48 组使用的名单另需逐对验收。
2. 额外市场数据先从机构原始表或法规逐条取证。用 `--evidence` 传同一 `pair_id` 和 `locale` 的 JSON，`facts` 中每条含唯一 `id`、`source_url`、`locator`，及 `value`。在值内保留可重核的单位和适用范围。校验器对主张只做机械检查。授权、法规时效、工资口径、来源实质和段落质量由编辑核。补充表 A 的资格线索按岗使用，招聘样本与「适用待核」进入降级分支。
3. 续跑或交付前重抽。批次文件哈希用来追溯版本；逐稿 `input_hash` 只绑定本对、本语言的输入。名称与 Career Current 发生冲突即停止使用该稿。若后者更名，先同步译名证据再重抽。已发布内容的受影响项交给原发布责任方复核。内容变化以新 `draft_hash` 验收。冻结稿和来源后交独立角色核查；记录结论所覆盖的字节。

## 八个模块

1. 开篇答两岗交付或责任分界。数字有同口径且能帮读者选择时使用。
2. 对照任务、工作环境、入行门槛和同口径市场数据。薪资分位描述分布，岗位空缺采用该来源的定义。
3. 在同一真实工作情境下写两岗的动作、交付和交接。对于重叠职责，分别写决策权限、监督和执行。法域和机构范围随具体例子说明。
4. 解释各自的入行路径。英文面向美国，用 BLS/O*NET 和适用的州或市执照权威。简中面向中国大陆，区分岗位名、职称、技能等级、作业或执业证书；缺少适用资格来源时写求职者如何按工作和授权核实。机构分工、专业代码和持证结论都需要对应证据。
5. 对照工作条件与读者该问的事。若采用 O*NET CXP，明确这是调查回答的加权比例。量表档位、样本、年份、不确定性和统计解释可放方法说明。
6. 给两岗间真实的转岗切入点，写能复用的成果和需要补的能力。
7. 给约 30 分钟的低成本纸面试做，交代材料、选择与结果。涉及电气、医疗或特种设备时，仅用虚构数据、教学材料和桌面分析。
8. 给高意图可见 FAQ 和合适的下一步，提供单岗档案和适用测评入口。问答按需要数量设置。

引用直达适用的制度、统计表、标准或原始工作材料。全国规则优先中央来源，州、市或地方公开材料可以支持各自范围内的事实和示例。去掉具体在招职位、过期课表和空首页。遇到数字缺口，省略无法同口径的比较。

## 候选格式与校验

Frontmatter 使用 JSON 对象（YAML 1.2 子集），用 `---` 行包裹。写入 `title`、`description`、`locale`、`pair_id`、`canonical`、`input_hash`、`faq_items`（含 `question` 和 `answer` 的数组）。将 `<!-- comparison-faq -->` 放在第八节问答处，由该数组提供可见内容。每组每语言一篇，拟定路由由排序后的 slug 推导；待接入时验证实际 canonical、反序 301、内链和 Sitemap。

运行 `python3 scripts/lint_compare_batch.py --check CONTEXT DRAFT`。双语或多稿可重复传 `--check`。通过仅代表候选契约合格。定稿复核场景、制度、来源和读者决策增量，疑似跨篇重复交编辑判断。

### 已审母版

按原交付包中的 `SHA256SUMS` 找回「职业对比样稿1_电工与电气工程师」v4。下列为独立审稿 PASS 对应字节；用于正文样式参考，正式生成候选仍使用上面的 Frontmatter 契约。

| 文件 | SHA-256 |
|---|---|
| en.md | `4645a852f4a6acf79034818495b143c7ebf66fe0938b5b7e48d018336556dd66` |
| zh-CN.md | `da4240e7b34ecc55f3aa9797a1058aab405db7e2eebf8f574dbe22be21fc9de2` |
| sources.txt | `819185e396343d6de6713a594834b6c456103789f3c23d724e21584933d13578` |
