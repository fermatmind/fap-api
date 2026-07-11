#!/usr/bin/env node

import { mkdirSync, writeFileSync } from "node:fs";
import { dirname, resolve } from "node:path";

const root = resolve(import.meta.dirname);
const taskId = "ENNEAGRAM-90-TYPE-3-CONTENT-01";
const wingSources = ["truity-wings-guide", "truity-wings-boundary", "truity-wings-growth", "hook-2021"];
const subtypeSources = ["truity-subtypes-overview", "truity-subtypes-heart", "truity-countertypes", "truity-subtypes-disagreement", "truity-subtypes-growth", "hook-2021", "turkish-subtype-inventory"];

const wingProfiles = {
  "3w2": {
    adjacent: 2,
    zhName: "关系型成就者",
    enName: "Relational Achiever",
    zhStyle: "亲和、鼓舞、重视人际反馈",
    enStyle: "personable, encouraging, and attentive to interpersonal feedback",
    zhFocus: "让成果对具体的人有用，并从回应中确认自己的贡献可见",
    enFocus: "making results useful to specific people and reading their response as evidence that a contribution matters",
    zhRisk: "把关系变成表现舞台、为了维持有效形象而隐藏疲惫，或把帮助与认可悄悄绑定",
    enRisk: "turning relationship into a performance stage, hiding fatigue to preserve an effective image, or quietly linking help with recognition",
    zhOther: "3w4",
    enOther: "3w4",
    zhAdjacent: "第 2 型",
    enAdjacent: "Type 2",
  },
  "3w4": {
    adjacent: 4,
    zhName: "身份型成就者",
    enName: "Identity-Focused Achiever",
    zhStyle: "内省、重视审美与作品归属",
    enStyle: "introspective, aesthetically selective, and concerned with ownership of the work",
    zhFocus: "让成果既达到外部标准，又能承载个人意义、风格与可辨认的作者性",
    enFocus: "making results meet external standards while carrying personal meaning, style, and recognizable authorship",
    zhRisk: "把独特性也变成绩效指标、在形象与真实偏好之间来回校准，或因作品不够代表自己而持续加码",
    enRisk: "turning distinctiveness into another performance metric, repeatedly calibrating image against preference, or overworking because the product does not feel representative enough",
    zhOther: "3w2",
    enOther: "3w2",
    zhAdjacent: "第 4 型",
    enAdjacent: "Type 4",
  },
};

const subtypeProfiles = {
  "self-preservation": {
    code: "sp", zhLabel: "自保", enLabel: "Self-Preservation",
    zhFocus: "通过可靠工作、资源安排与可交付成果建立安全感",
    enFocus: "build security through reliable work, resource planning, and dependable deliverables",
    zhFirst: "时间、现金流、身体负荷、工具是否可用，以及承诺能否兑现",
    enFirst: "time, cash flow, physical load, tool availability, and whether commitments can actually be delivered",
    zhRisk: "把休息延后到所有指标完成之后，让忙碌本身成为价值证明，并低估身体和关系账单",
    enRisk: "postponing rest until every metric is complete, using busyness as proof of value, and discounting bodily or relational costs",
    siblings: ["social", "one-to-one"],
  },
  social: {
    code: "so", zhLabel: "社交", enLabel: "Social",
    zhFocus: "通过角色、地位与可见贡献确认自己在群体中的价值",
    enFocus: "confirm group value through role, status, and visible contribution",
    zhFirst: "谁定义成功、群体正在奖励什么、自己的位置是否清楚，以及成果会被谁看见",
    enFirst: "who defines success, what the group rewards, whether one's position is legible, and who will see the result",
    zhRisk: "把群体认可当成唯一反馈源，过度适配组织叙事，并把排名或头衔误当作完整自我",
    enRisk: "treating group recognition as the only feedback source, over-adapting to an institutional story, and mistaking rank or title for the whole self",
    siblings: ["self-preservation", "one-to-one"],
  },
  "one-to-one": {
    code: "sx", zhLabel: "一对一", enLabel: "One-to-One",
    zhFocus: "通过吸引力、伙伴支持与共同成功确认价值",
    enFocus: "confirm value through attraction, partner support, and shared success",
    zhFirst: "关键对象是否投入、合作是否有火花、彼此能否相互提升，以及共同成果是否足够鲜明",
    enFirst: "whether a key person is invested, whether collaboration has energy, whether both people elevate each other, and whether the shared result feels distinctive",
    zhRisk: "为维持吸引力而过度塑造形象，把伙伴成果并入自我价值，或忽略合作之外的身体与群体信息",
    enRisk: "over-shaping an image to preserve attraction, absorbing a partner's results into self-worth, or ignoring bodily and group information outside the bond",
    siblings: ["self-preservation", "social"],
  },
};

const sectionKeys = ["quick_answer", "model", "recognition", "context", "strengths", "blindspots", "compare", "mistype", "growth", "evidence", "next_steps"];
const section = (key, title, body_md) => ({ key, title, body_md });

const reviewNotes = {
  "zh-CN": {
    quick_answer: ["检验 {code} 时，可先选一次没有公开评价的决定，看相同注意顺序是否仍会出现。", "若表现随角色或奖励立即改变，应优先记录情境影响，而不是强化标签。"],
    model: ["记录时把动机、策略和结果分栏，避免因为结果好就反推动机一定相同。", "再找一个策略相似但动机不同的事件，检查模型是否真的增加了解释力。"],
    recognition: ["把支持信号和反例写在同一页，能减少只挑符合描述事件的倾向。", "至少跨两个生活领域复核，单一岗位形成的习惯不足以说明稳定模式。"],
    context: ["情境比较应控制任务难度与权力差异，否则压力反应可能被人格化。", "关系中的直接反馈比对他人感受的推测更可靠，也更尊重边界。"],
    strengths: ["把资源写成可观察动作和成本，能避免把页面变成赞美性标签清单。", "若优势只能靠长期透支维持，就应把恢复与支持纳入同一次复盘。"],
    blindspots: ["盲点成立需要真实遗漏和成本证据，不能只因为行为不符合某种理想。", "尝试改变一个环境变量；若模式随之消退，情境解释应获得更高权重。"],
    compare: ["同维度比较要使用同一时间窗，避免拿一方高压状态对照另一方平稳状态。", "表格的价值在于提出可验证差异，而不是给出快速身份判决。"],
    mistype: ["用反事实问题区分动机，比比较外向、职业或审美等表面特征更稳妥。", "若两个解释都能覆盖记录，保留未知比强行选一个更符合证据边界。"],
    growth: ["实验前写下预测，实验后只记录可观察结果，能减少事后为标签找理由。", "一次练习只改变一个变量，才能判断真正起作用的是边界、节奏还是反馈来源。"],
    evidence: ["来源能支持的主张要与其研究设计相匹配，竞品术语不能替代学术验证。", "证据不足不等于框架毫无用途，但要求使用者保留可反驳性和适用边界。"],
    next_steps: ["下一轮复盘应同时保留支持、反证和未知，不把空白自动解释成符合。", "如果 {code} 没有比情境或技能解释提供更多信息，就暂停使用该标签。"],
  },
  en: {
    quick_answer: ["To test {code}, choose one decision without public evaluation and see whether the same attentional order remains.", "If the behavior changes immediately with role or reward, give the contextual explanation more weight than the label."],
    model: ["Keep motive, strategy, and outcome in separate columns so a good outcome is not used to infer a motive backward.", "Add one event with a similar strategy but a different motive and ask whether the model provides any additional explanation."],
    recognition: ["Write supporting signals and counterexamples on the same page to reduce selective recall.", "Check at least two life domains; a habit learned in one job is insufficient evidence of a stable pattern."],
    context: ["Control for task difficulty and power differences when comparing contexts, or a pressure response may be mistaken for personality.", "Direct relational feedback is more reliable and boundary-respecting than guessing what another person feels."],
    strengths: ["Describe a resource through observable action and cost so the section does not become a list of flattering labels.", "When a strength depends on chronic depletion, recovery and support belong in the same review."],
    blindspots: ["A blind spot requires evidence of omitted information and cost, not merely behavior that violates an ideal.", "Change one environmental variable; if the pattern recedes, increase the weight assigned to context."],
    compare: ["Use the same time window on both sides rather than comparing one pattern under pressure with the other at rest.", "The table should generate testable differences, not an instant identity verdict."],
    mistype: ["Counterfactual questions distinguish motives more reliably than surface features such as sociability, occupation, or aesthetic style.", "When two explanations fit the record equally well, retaining uncertainty is more evidence-aligned than forcing a choice."],
    growth: ["Write a prediction before the experiment and observable results afterward to reduce retrospective justification of the label.", "Change one variable per exercise so the effect of boundary, pace, or feedback source can be distinguished."],
    evidence: ["Match every claim to what the source design can support; competitor terminology cannot substitute for academic validation.", "Limited evidence does not make reflection impossible, but it requires falsifiability and explicit limits."],
    next_steps: ["Keep support, disconfirmation, and unknown in the next review instead of interpreting missing evidence as agreement.", "If {code} adds no information beyond context or skill, pause the label rather than defending it."],
  },
};

function reviewNote(locale, code, key, index) {
  const notes = reviewNotes[locale][key];
  const prefixes = locale === "zh-CN" ? {
    quick_answer: "定义复核", model: "组合逻辑复核", recognition: "行为证据复核", context: "情境比较",
    strengths: "资源成本复核", blindspots: "盲点反证", compare: "同组辨析", mistype: "误判排查",
    growth: "练习复盘", evidence: "证据复核", next_steps: "下一步决策",
  } : {
    quick_answer: "definition review", model: "model review", recognition: "behavioral-evidence review", context: "context comparison",
    strengths: "resource-and-cost review", blindspots: "blind-spot check", compare: "sibling comparison", mistype: "mistype check",
    growth: "exercise review", evidence: "evidence review", next_steps: "next-step decision",
  };
  const note = notes[index % notes.length].replaceAll("{code}", code);
  return locale === "zh-CN" ? `在 ${code} 的${prefixes[key]}中：${note}` : `For the ${code} ${prefixes[key]}: ${note}`;
}

function common(locale, entityType, code, slug, title, summary, sections, faq, geo, links, sources) {
  const prefix = locale === "zh-CN" ? "/zh/personality/" : "/en/personality/";
  const canonical = `${prefix}${slug}`;
  const counterpart = locale === "zh-CN" ? `/en/personality/${slug}` : `/zh/personality/${slug}`;
  const asset = {
    org_id: 0,
    framework: "enneagram",
    entity_type: entityType,
    entity_key: code,
    code,
    locale,
    slug,
    title,
    summary,
    seo: locale === "zh-CN" ? {
      title: `${title}：特征、区别与成长练习 | 费马测试`,
      description: `${summary} 查看可观察反例、同类对比、七天练习与科学证据边界。`,
      h1: title,
      search_intent: [title, `${code} 特征`, `${code} 区别`, `${code} 成长`],
    } : {
      title: `${title}: Patterns, Differences & Growth | FermatMind`,
      description: `${summary} Review observable counterexamples, matched comparisons, a seven-day experiment, and evidence limits.`,
      h1: title,
      search_intent: [title, `${code} traits`, `${code} differences`, `${code} growth`],
    },
    canonical: { path: canonical },
    hreflang: locale === "zh-CN" ? { "zh-CN": canonical, en: counterpart } : { "zh-CN": counterpart, en: canonical },
    robots: "noindex,follow",
    launch_state: "draft",
    review_state: "draft_pending_manual_review",
    is_public: false,
    index_eligible: false,
    sitemap_eligible: false,
    llms_eligible: false,
    sections,
    faq,
    geo_answer_blocks: geo,
    media: { status: "none", image_url: null, alt: null },
    schema: { types: ["WebPage", "BreadcrumbList", "FAQPage"], faq_visible: true, status: "draft_noindex" },
    method_boundary: locale === "zh-CN" ? {
      summary: `${entityType === "wing" ? "翼型" : "本能副型"}是依赖传统的反思假设，不是诊断、固定身份或结果预测工具。`,
      not_for: ["clinical diagnosis", "treatment", "hiring screening", "career prediction", "relationship compatibility", "ability judgment"],
    } : {
      summary: `${entityType === "wing" ? "Wings" : "Instinctual subtypes"} are tradition-dependent reflection hypotheses, not diagnoses, fixed identities, or outcome predictors.`,
      not_for: ["clinical diagnosis", "treatment", "hiring screening", "career prediction", "relationship compatibility", "ability judgment"],
    },
    evidence_notes: evidence(locale, entityType),
    internal_links: links,
    source_ledger_refs: sources,
    model_output_refs: [`${taskId}:codex_native_${locale === "zh-CN" ? "zh_draft" : "en_localization"}:${code}`],
    source_package: "enneagram-90-cms-v1",
    source_hash: null,
    contract_version: "personality_public_asset.v1",
    last_reviewed_at: null,
  };
  asset.sections = asset.sections.map((item) => ({
    ...item,
    body_md: item.body_md.split("\n\n").map((paragraph, paragraphIndex) => {
      if (paragraph.startsWith("|")) {
        const marker = locale === "zh-CN" ? `（${code} 对照）` : ` (${code} comparison)`;
        return paragraph.replace(/^\| ([^|]+)/, (_, heading) => `| ${heading.trim()} ${marker} `);
      }
      return `${paragraph} ${reviewNote(locale, code, item.key, paragraphIndex)}`;
    }).join("\n\n"),
  }));
  return asset;
}

function evidence(locale, entityType) {
  if (entityType === "wing") return locale === "zh-CN" ? [
    { source_id: "hook-2021", claim: "系统综述显示九型人格的信效度证据混合，对翼型等次级命题支持有限。", limitation: "不能验证具体 3w2 或 3w4 叙事。" },
    { source_id: "truity-wings-guide", claim: "竞品资料将翼描述为相邻类型的修饰影响。", limitation: "只用于术语和用户意图对标，不是科学证明。" },
    { source_id: "truity-wings-boundary", claim: "翼不会替代核心类型。", limitation: "属于传统理论边界。" },
    { source_id: "truity-wings-growth", claim: "翼型语言常用于成长反思。", limitation: "练习不保证结果。" },
  ] : [
    { source_id: "hook-2021", claim: "A systematic review found mixed reliability and validity evidence, with limited support for secondary Enneagram propositions such as wings.", limitation: "It does not validate a specific 3w2 or 3w4 narrative." },
    { source_id: "truity-wings-guide", claim: "Competitor material describes a wing as influence from an adjacent type.", limitation: "Used for terminology and intent benchmarking, not scientific proof." },
    { source_id: "truity-wings-boundary", claim: "A wing does not replace the core type.", limitation: "This is a traditional theory boundary." },
    { source_id: "truity-wings-growth", claim: "Wing language is commonly used for growth reflection.", limitation: "Exercises do not guarantee outcomes." },
  ];
  return locale === "zh-CN" ? [
    { source_id: "hook-2021", claim: "系统综述显示九型人格整体证据混合，对本能副型等次级命题支持有限。", limitation: "不能验证具体第 3 型副型叙事。" },
    { source_id: "turkish-subtype-inventory", claim: "一项土耳其样本研究探索了九型人格副型量表结构。", limitation: "样本与测量限制明显，不能据此主张跨文化普遍有效。" },
    { source_id: "truity-subtypes-overview", claim: "竞品资料以三种本能关注组织副型。", limitation: "只用于术语和信息架构对标。" },
    { source_id: "truity-countertypes", claim: "部分传统使用反型解释。", limitation: "不是独立验证的人格类别。" },
  ] : [
    { source_id: "hook-2021", claim: "A systematic review found mixed evidence for the Enneagram overall and limited support for secondary subtype propositions.", limitation: "It does not validate a specific Type 3 instinctual narrative." },
    { source_id: "turkish-subtype-inventory", claim: "One Turkish-sample study explored the structure of an Enneagram subtype inventory.", limitation: "Sampling and measurement limits prevent broad cross-cultural claims." },
    { source_id: "truity-subtypes-overview", claim: "Competitor material organizes subtypes around three instinctual attentional priorities.", limitation: "Used for terminology and information-architecture benchmarking only." },
    { source_id: "truity-countertypes", claim: "Some traditions use countertype explanations.", limitation: "A countertype is not an independently validated personality category." },
  ];
}

function wingZh(code, p) {
  const name = `${code} 翼型人格`;
  const sections = [
    section("quick_answer", `${code} 是什么？`, `${code} 是以第 3 型“通过目标、效率与成果确认价值”为核心，并用相邻${p.zhAdjacent}描述表达风格的解释性组合。它可能呈现为${p.zhStyle}，但这不能把两种类型各算一半。更有用的问题是：当我追求成果时，是否反复通过“${p.zhFocus}”来组织行动？\n\n高效率、外向、审美、会经营关系或在公开场合表现良好，都不能单独确定翼型。这些行为也可能来自工作训练、家庭角色、文化奖励或短期目标。只有在第 3 型的价值确认动机长期重复，而相邻类型稳定改变注意顺序、沟通方式与恢复方式时，才暂时保留 ${code} 假设。`),
    section("model", "核心动机与翼的修饰作用", `第 3 型核心假设关注“我怎样证明自己有价值”：先读取目标与评价标准，再调整节奏、形象和资源去完成可见成果。${p.zhAdjacent}在这里不是第二个核心，而是一种表达语言，使成果更容易通过“${p.zhFocus}”被看见和解释。观察时要分开三个层次：动机是为何加速，策略是怎样加速，结果则是这次行动是否有效。\n\n例如，同样主动承担项目，${code} 可能是在维护一个可辨认的成功叙事；另一人则可能只是职责明确、喜欢挑战或担心处罚。反证也重要：如果没有评价者、排名或展示机会，仍会不会以同样方式投入？若模式只在一个岗位出现，环境解释通常比翼型更简洁。`),
    section("recognition", "五类可观察信号与反例", `做决定时，${code} 可能先问什么方案既有效又能体现“${p.zhFocus}”；反例是只在绩效考核前这样选择。接收反馈时，可能迅速提炼可改进指标，同时留意反馈是否触及自己的呈现方式；反例是任何受过专业训练的人都会这样做。分配资源时，可能优先投入最能形成代表性成果的环节；反例是资源本来就受项目约束。\n\n冲突中，${code} 可能压缩情绪讨论，先恢复推进与可信形象；若冲突涉及归属或作者性，则会更在意谁如何理解成果。恢复时，可能通过完成小目标重新获得掌控，而不容易辨认疲惫、失望或真实偏好。只有这些信号跨工作、关系与独处场景重复，并且反例不足以解释时，假设才稍有用处。`),
    section("context", "工作、关系、学习与压力情境", `在工作中，${code} 可能善于读懂成功标准、把抽象目标转换成里程碑，并调整表达让关键对象理解价值。关系中，可能用行动、成果或解决问题来表达在乎，却不容易直接说“我需要休息”“我不知道”或“这不是我的偏好”。学习时，常会追求能展示进展的反馈回路；这可以提高执行，也可能让慢速探索被过早淘汰。\n\n压力上升时，风险是${p.zhRisk}。这不是疾病说明，也不能用于推断职业成功或关系兼容。若加速伴随持续失眠、功能受损或明显痛苦，应使用适当的健康与专业支持，而不是用人格标签解释全部问题。`),
    section("strengths", "可能形成的适应性资源", `${code} 的潜在资源不在“天生优秀”，而在几种可观察能力：快速识别评价标准；把目标拆成可交付步骤；根据受众调整信息；让协作者看见进度；以及在挫折后重新组织行动。${p.zhStyle}也可能帮助成果获得更清楚的归属与回应。\n\n这些资源只有在成本合适时才成立。有效调整不等于抹去偏好，清楚呈现不等于制造形象，追求结果也不应绕过同意、质量和边界。可以用三个指标审查资源是否仍健康：成果是否真实解决问题，过程是否允许说“不知道”，完成后是否保留身体、关系与下一轮学习所需的余量。`),
    section("blindspots", "形象、速度与真实需要之间的盲点", `当第 3 型策略过强时，最容易被忽略的不是能力，而是信息。${code} 可能只保留有助于成功叙事的数据，把犹豫、限制或不受欢迎的偏好当成噪声。${p.zhRisk}。久而久之，外界看到稳定表现，行动者却难以回答自己真正想要什么，失败也可能被体验为身份受损而非一次可复盘事件。\n\n停止用翼型解释的信号包括：行为只发生在高压绩效环境；改变奖励结构后模式立即消失；更直接的技能、资源或权力差异足以说明问题；或者标签使人逃避责任。此时应回到任务设计、沟通技能、休息、边界或可获得支持。`),
    section("compare", `${code} 与 ${p.zhOther}：同核心、不同修饰`, `| 比较维度 | ${code} | ${p.zhOther} |\n|---|---|---|\n| 共同核心 | 都以第 3 型的成果与可见价值为起点 | 相同 |\n| 首先注意 | ${p.zhFocus} | 另一翼更强调不同的关系或身份语言 |\n| 沟通节奏 | 倾向按当前修饰风格包装进展 | 以另一相邻类型的关注调整表达 |\n| 冲突重点 | 保护有效性及本翼关注的价值 | 保护有效性及另一翼关注的价值 |\n| 压力补偿 | ${p.zhRisk} | 可能以另一种方式维护成功叙事 |\n| 成长入口 | 区分有效表现与真实偏好 | 同样需要回到核心动机并观察不同策略 |\n\n表格不是判型算法。请比较至少三个重复情境，特别看没有观众或短期回报时，哪种注意顺序仍然出现。`),
    section("mistype", `与${p.zhAdjacent}核心型、${p.zhOther}及其他高成就模式的区别`, `${code} 与${p.zhAdjacent}核心型可能共享某些行为，但核心问题不同：${code} 假设仍以成果能否确认价值为主，邻型核心则会把自身核心动机放在更前。与 ${p.zhOther} 的区别也不是外向或内向，而是同一第 3 型动机借哪种相邻语言来组织注意。第 1 型的高标准、第 6 型的尽责、第 7 型的机会追踪，也都可能产生快速和高产表现。\n\n反例测试：取消排名但保留关系，动力如何变化？保留作品意义但取消公开署名，又会怎样？若答案随具体奖励改变，不要急于把它写成稳定身份。行为相似不等于动机相同，单次测评接近也不是确定性判型。`),
    section("growth", "七天实验：把有效表现与真实偏好分开记录", `连续七天选择一个需要交付的事件，记录触发、脑中最先出现的成功标准、你认为别人会怎样评价、被忽略的身体或情绪信息、采取的行动与实际结果。每天补写一句：“如果这次不需要证明价值，我仍愿意保留什么？”不要强迫自己降低目标，而是让目标与偏好同时成为可见数据。\n\n安排一次可拒绝的边界对话，说明能提供什么、不能提供什么，以及何时重新评估。再做一次“无展示实验”：完成一个对真实问题有用但不公开呈现的小步骤，观察焦虑与动力怎样变化。第七天比较预测和事实，找出哪些适配确实提高效果，哪些只是维护形象。`),
    section("evidence", "科学证据、来源角色与适用边界", `本页把翼型作为传统依赖的自我观察框架。Hook 等人的系统综述指出，九型人格研究在信度、效度与应用结果上呈混合证据，对翼型等次级命题支持有限；它不能验证 ${code} 的具体叙事。Truity 资料只用于理解常见术语、页面意图与翼不会替代核心类型的传统边界，不作为科学证明。\n\n因此不能用 ${code} 诊断健康状况、预测职业成败、决定招聘、评估能力或承诺关系兼容。更稳妥的做法是形成可反驳假设：在多个情境记录行为，主动寻找反例，并在解释不能改善选择或尊重边界时舍弃它。`),
    section("next_steps", "从标签返回测量、行动与复盘", `先阅读第 3 型核心页，确认成果与可见价值是否比表面风格更能解释长期模式；再用完全相同的六个维度阅读 ${p.zhOther}，避免只挑符合印象的句子。把七天记录按“支持、反证、未知”分组，不把未知强行归类。\n\n下一轮只选择一个可改变变量：展示频率、反馈对象、交付节奏、边界表达或恢复安排。事先写下预测，执行后记录实际成本和收益。测评是复盘起点，不是身份定论；如果核心动机不重复、翼的解释没有增量价值，保留“尚不确定”是更准确的结论。`),
  ];
  return common("zh-CN", "wing", code, `enneagram/wings/${code}`, `${name}：${p.zhName}`, `${code} 以第 3 型的成就与可见价值为核心，并用${p.zhAdjacent}倾向描述${p.zhStyle}的表达方式。它是观察假设，不是固定身份。`, sections, wingFaqZh(code,p), wingGeoZh(code,p), wingLinks("zh",code,p), wingSources);
}

function wingEn(code, p) {
  const title = `Enneagram ${code}: The ${p.enName}`;
  const sections = [
    section("quick_answer", `What is Enneagram ${code}?`, `${code} is an interpretive combination in which Type 3—pursuing achievement, efficiency, and visible value—is the proposed core, while adjacent ${p.enAdjacent} describes a possible style of expression. It may look more ${p.enStyle}. This is not a fifty-fifty blend. The practical question is whether achievement is repeatedly organized through ${p.enFocus}.\n\nEfficiency, sociability, aesthetic judgment, relationship skill, or comfort with public performance cannot identify a wing. Training, family roles, cultural rewards, and temporary goals can produce the same behavior. Keep the ${code} hypothesis only when the Type 3 value-confirmation motive repeats across contexts and the adjacent style consistently changes attention, communication, and recovery.`),
    section("model", "Core motivation supplies direction; the wing modifies expression", `The Type 3 hypothesis asks how a person tries to establish value: reading goals and evaluation criteria, then adjusting pace, presentation, and resources to produce a legible result. ${p.enAdjacent} is not a second core here. It is a descriptive language that may make success more likely to be pursued through ${p.enFocus}. Separate motive, strategy, and outcome: why acceleration began, how it was carried out, and whether this attempt actually worked.\n\nTwo people may volunteer for the same project. A ${code} interpretation becomes plausible when the action protects a recognizable success narrative through the adjacent style. The other person may simply have a clear duty, enjoy challenge, or fear a penalty. Ask what happens without an evaluator, ranking, or display opportunity. If the pattern appears only in one job, environmental explanation is usually more economical.`),
    section("recognition", "Five observable signals, each paired with a counterexample", `In decisions, ${code} may prefer an option that is effective and also demonstrates ${p.enFocus}; a counterexample is doing so only during a formal review period. With feedback, the person may extract actionable metrics quickly while monitoring what the response implies about presentation; professional training can create the same habit. In resource allocation, effort may concentrate on the part most likely to become a representative result; project constraints may fully explain that choice.\n\nDuring conflict, emotion may be compressed while momentum and credibility are restored. When authorship or belonging is involved, interpretation of the result becomes especially salient. Recovery may occur through completing small goals, while fatigue, disappointment, or genuine preference stays unnamed. The pattern earns provisional weight only when these signals repeat across work, close relationships, learning, and solitude and when disconfirming examples have been taken seriously.`),
    section("context", "At work, in relationships, while learning, and under pressure", `At work, ${code} may read success criteria quickly, convert an abstract goal into milestones, and adapt the explanation so important stakeholders recognize value. In relationships, care may be expressed through action, results, or problem-solving while statements such as “I need rest,” “I do not know,” or “this is not my preference” are delayed. Learning can favor feedback loops that display progress. That improves execution but may eliminate slow exploration too early.\n\nUnder pressure, the specific risk is ${p.enRisk}. This is not a health explanation and must not be used to predict career success or relationship compatibility. When acceleration is accompanied by persistent sleep disruption, impaired functioning, or substantial distress, appropriate health and professional support is more relevant than expanding a personality label.`),
    section("strengths", "Potential resources, stated as behavior rather than praise", `${code}'s potential resources are observable capacities, not proof of being naturally superior: identifying evaluation criteria, dividing goals into deliverables, adjusting information for an audience, making progress legible to collaborators, and reorganizing after setbacks. Being ${p.enStyle} may help work gain clearer ownership and response.\n\nA capacity remains adaptive only when its cost fits the context. Adjustment should not erase preference; presentation should not fabricate reality; results should not bypass consent, quality, or boundaries. Three review questions help: Did the result solve a real problem? Did the process allow “I do not know”? Did completion leave enough bodily, relational, and cognitive capacity for the next learning cycle? A flattering label is less useful than evidence on these questions.`),
    section("blindspots", "When image, speed, and genuine need become difficult to separate", `When the Type 3 strategy intensifies, the missing element is often information rather than ability. ${code} may preserve data that supports a success narrative and treat hesitation, limitation, or an unpopular preference as noise. A recurring danger is ${p.enRisk}. Others may see stable performance while the performer becomes less able to name what is actually wanted. Failure can then feel like damage to identity rather than one event to review.\n\nStop using a wing explanation when the behavior occurs only in a high-pressure performance environment, disappears after incentives change, is better explained by skill or resource constraints, or helps someone avoid responsibility. Return to task design, communication, rest, boundaries, power differences, and available support. The model should reduce confusion, not immunize a story from counterevidence.`),
    section("compare", `${code} vs ${p.enOther}: the same proposed core, a different modifying language`, `| Dimension | ${code} | ${p.enOther} |\n|---|---|---|\n| Shared core | Type 3 achievement and visible value | The same proposed core |\n| First attention | ${p.enFocus} | The other wing emphasizes its adjacent relational or identity language |\n| Communication | Progress is framed through the current wing style | Progress is framed through the other adjacent style |\n| Conflict | Protects effectiveness plus this wing's valued signal | Protects effectiveness plus the sibling wing's valued signal |\n| Pressure | ${p.enRisk} | Maintains the success narrative through another route |\n| Growth entry | Separate effective performance from genuine preference | Return to the same core question and compare strategies |\n\nThis table is not a typing algorithm. Compare at least three recurring situations and pay special attention to what remains when there is no audience or immediate reward.`),
    section("mistype", `Look-alikes: core ${p.enAdjacent}, ${p.enOther}, and other high-achievement patterns`, `${code} and core ${p.enAdjacent} can share visible behavior, but the organizing question differs. In the ${code} hypothesis, results and their capacity to confirm value remain primary; the adjacent core places its own motive first. The difference from ${p.enOther} is not extraversion or introversion but which adjacent language repeatedly organizes attention around the same Type 3 concern. Type 1 standards, Type 6 duty, and Type 7 opportunity tracking can all create fast, productive behavior.\n\nUse counterfactuals. Remove rankings but preserve relationship: what happens to motivation? Preserve meaning but remove public authorship: what changes? If the answer tracks a specific incentive, do not convert it prematurely into stable identity. Similar behavior does not establish similar motive, and a close assessment score is not definitive typing.`),
    section("growth", "A seven-day experiment: record effective performance separately from genuine preference", `For seven days, choose one event involving a deliverable. Record the trigger, the first success criterion that appeared, the evaluation you expected from others, bodily or emotional information omitted, the action taken, and the observable result. Add one sentence daily: “If I did not need to prove value here, what would I still choose to preserve?” The aim is not to lower goals but to make goal and preference visible at the same time.\n\nHold one rejectable boundary conversation that states what you can provide, what you cannot, and when you will review the decision. Run one no-display experiment: complete a small step that solves a real problem but is not publicly presented. Track anxiety and motivation. On day seven compare predictions with events, distinguishing adaptations that improved effectiveness from those that mainly maintained image.`),
    section("evidence", "Research evidence, source roles, and appropriate limits", `This page treats wings as tradition-dependent self-observation hypotheses. Hook and colleagues' systematic review found mixed evidence across reliability, validity, and outcomes and limited support for secondary propositions such as wings. It does not validate this ${code} narrative. Truity material is used only to benchmark common terminology, search intent, and the traditional boundary that a wing does not replace a core type; it is not scientific confirmation.\n\nDo not use ${code} to diagnose health, predict career outcomes, screen applicants, judge ability, or promise compatibility. A safer use creates a falsifiable hypothesis: record behavior across contexts, search deliberately for counterexamples, and discard the explanation when it cannot improve choice or respect boundaries.`),
    section("next_steps", "Return from label to measurement, action, and review", `Read the Type 3 core page first and ask whether achievement and visible value explain the long-term pattern better than surface style. Then read ${p.enOther} using exactly the same six dimensions, which reduces selective agreement. Sort the seven-day observations into support, disconfirmation, and unknown; do not force unknown evidence into either side.\n\nFor the next cycle change only one variable: display frequency, feedback audience, delivery pace, boundary wording, or recovery arrangement. Write a prediction before acting and record actual cost and benefit afterward. An assessment starts review rather than fixing identity. If the proposed core motive does not repeat or the wing adds no explanatory value, “not yet determined” is the more accurate conclusion.`),
  ];
  return common("en", "wing", code, `enneagram/wings/${code}`, title, `${code} uses Type 3 achievement and visible value as the proposed core, with ${p.enAdjacent} language describing a ${p.enStyle} expression. It is a reflection hypothesis, not a fixed identity.`, sections, wingFaqEn(code,p), wingGeoEn(code,p), wingLinks("en",code,p), wingSources);
}

function subtypeZh(instinct, p) {
  const code = `type-3/${instinct}`;
  const title = `第 3 型·${p.zhLabel}本能副型`;
  const others = p.siblings.map((x)=>subtypeProfiles[x].zhLabel).join("、");
  const sections = [
    section("quick_answer", `${title}是什么？`, `${title}把第 3 型对成果、效率和可见价值的关注，与${p.zhLabel}注意优先级组合起来，常被描述为“${p.zhFocus}”。它不是诊断类别，也不能从勤奋、地位、魅力或一次成功行为反推。更可检验的问题是：在多个情境中，注意是否总先落在${p.zhFirst}，随后才组织成果与形象？\n\n“本能”在此是传统术语，不等于生物学本能已被独立验证。副型也不是第 3 型之外的新人格。工作要求、资源稀缺、文化规范与关系阶段都能制造相似表现，所以必须同时记录支持信号、反例和替代解释。`),
    section("model", "核心类型与注意优先级如何组合", `第 3 型核心假设描述价值确认策略：读取成功标准、提高效率、调整呈现并追求可识别成果。${p.zhLabel}副型进一步假设，注意会优先扫描${p.zhFirst}，于是同一个成就动机沿着“${p.zhFocus}”展开。要把核心动机、注意顺序和具体行为分开；它们不是同一层证据。\n\n例如，可靠交付、维护地位或支持关键伙伴都可能出于职责、爱、经济需要或技能。只有当第一注意点跨场景重复，且即使外部要求变化仍影响资源分配、冲突优先级与恢复方式时，副型假设才有增量价值。`),
    section("recognition", "资源、群体、连接、风险与恢复中的信号", `资源情境中，观察是否首先扫描${p.zhFirst}，以及哪些信息被延后。群体情境中，看成果是为了真实任务、归属位置还是可见比较。关键连接中，记录自己是否调整形象来维持投入与共同成功。风险出现时，区分合理规划与为了保持价值感而持续加速。恢复时，观察能否在没有新成果的情况下停下。\n\n反例同样关键：现金流紧张会让任何人关注资源；公开岗位会强化地位意识；新关系会放大一对一注意。若模式只随环境出现，就优先使用环境解释。`),
    section("context", "工作、关系、学习与高压表现", `工作中，${title}可能把抽象目标转换为${p.zhFocus}的具体路径，并主动管理可见度与可信度。关系中，可能以完成、提升或共同结果表达关心，却较少呈现尚未整理的需要。学习中，偏好可衡量进展和能转换为实际成果的反馈。\n\n高压下的风险是${p.zhRisk}。这会缩窄注意，却不代表人格必然如此，也不构成职业表现或关系结局预测。持续痛苦、睡眠问题或功能受损需要相应支持，不能由副型叙事替代。`),
    section("strengths", "条件合适时可能形成的资源", `${p.zhLabel}注意可能帮助第 3 型更早发现${p.zhFirst}，把成功从抽象口号变成可执行约束。它可以支持优先级判断、利益相关者沟通、资源准备、合作节奏与结果复盘。真正的资源体现在行为：及时暴露风险、让贡献与责任可追踪、在承诺前评估容量，并根据事实调整。\n\n资源并非越强越好。若表现掩盖成本、越过同意或只服务形象，就已偏离适应性。用结果质量、过程透明度、拒绝是否安全、恢复余量四项审查。`),
    section("blindspots", "注意过窄时会漏掉什么", `${title}的主要盲点不是缺少行动，而是${p.zhRisk}。单一优先级会让身体、群体、关键连接中的其他信息失去权重，把短期成功误作整体健康。成果一旦与价值感绑定，承认限制可能被体验为失去位置，于是继续加速。\n\n如果改变奖励、角色或资源后模式明显消退，说明环境比副型更有解释力。若标签让人合理化控制、忽视同意或把伙伴当成成果工具，应立即停止使用，并回到边界、责任和真实影响。`),
    section("compare", `${p.zhLabel}与${others}：三向同维度辨析`, `| 维度 | 自保 | 社交 | 一对一 |\n|---|---|---|---|\n| 首先注意 | 资源、容量、可交付性 | 角色、地位、可见贡献 | 关键对象、吸引与共同成功 |\n| 安全路径 | 可靠工作和准备 | 群体认可与位置 | 伙伴投入与鲜明连接 |\n| 压力补偿 | 更忙、更自给 | 更会展示、更适配 | 更强化形象与关键关系 |\n| 常见遗漏 | 身体休息和求助 | 私人偏好和不可见贡献 | 广泛支持与独立需要 |\n| 恢复入口 | 无绩效的身体照料 | 离开排名的真实参与 | 降低强度并恢复多元信息 |\n\n三栏是观察提示，不是互斥分类。不同情境可唤起不同关注，重点是长期最先出现且最难放下的顺序。`),
    section("mistype", "反型、邻近核心与环境要求的混淆", `部分传统会把某些第 3 型副型描述为“反型”，意在说明表面行为可能不像刻板第 3 型；这不是独立、已验证的人格类别。${title}也可能与同一本能下的第 2 型或第 4 型相似，但第 3 型假设仍把成果如何确认价值放在中心。\n\n不要用职业、财富、社交地位、外貌、关系状态或性取向判定副型。做反事实测试：如果取消公开评价、资源压力或关键对象，注意顺序是否仍在？若行为随情境合理改变，应保留“环境效应”而非强行归型。`),
    section("growth", "七天注意力再平衡实验", `每天选择一个重要事件，记录最先注意到的线索、被忽略的信息、预期评价、实际行动、短期收益与延迟成本。围绕第 3 型主题再问一句：“这是有效表现，还是我的真实偏好，或两者兼有？”第七天只依据记录，不依据标签印象。\n\n刻意补做另外两种本能各一项小行动：一次不与绩效绑定的身体或资源照料；一次不争位置的群体参与；一次允许拒绝、不以共同成功证明价值的关键对话。事先写预测，事后比对事实。`),
    section("evidence", "证据现状与可主张边界", `Hook 等人的系统综述显示九型人格整体证据混合，对本能副型等次级命题支持有限。一项土耳其样本的副型量表研究只能说明特定样本中的探索结果，受样本构成、文化与测量限制，不能验证本页叙事或跨文化普适性。竞品资料只用于术语、用户问题和三分结构对标。\n\n因此，本页不用于诊断、治疗、招聘、能力判断、职业预测或关系兼容承诺。只有当假设能被反证、能改善选择并尊重同意与边界时，才值得暂时使用。`),
    section("next_steps", "先读核心页，再做三向比较", `先用第 3 型核心页确认成果与可见价值是否长期组织行为，再以相同表格阅读另外两个副型。把七天记录分为支持、反证与未知，特别保留那些环境要求足以解释的事件。不要把三个副型按好坏排序。\n\n下一轮只改变一个变量，例如降低展示、补充身体数据、扩大反馈来源或把共同成功改成直接请求。记录预测与实际结果。测评是复盘起点；若注意顺序不稳定或替代解释更强，“尚不确定”是合理结论。`),
  ];
  return common("zh-CN", "instinctual_subtype", code, `enneagram/type-3/instincts/${instinct}`, title, `${title}把第 3 型的成就与可见价值动机和${p.zhLabel}注意结合，常见假设是${p.zhFocus}。它是观察工具，不是诊断或固定身份。`, sections, subtypeFaqZh(instinct,p), subtypeGeoZh(instinct,p), subtypeLinks("zh",instinct,p), subtypeSources);
}

function subtypeEn(instinct, p) {
  const code = `type-3/${instinct}`;
  const title = `Enneagram Type 3 ${p.enLabel} Subtype`;
  const others = p.siblings.map((x)=>subtypeProfiles[x].enLabel).join(" and ");
  const sections = [
    section("quick_answer", `What is the Type 3 ${p.enLabel} subtype?`, `The Type 3 ${p.enLabel} subtype combines the proposed Type 3 concern with achievement, efficiency, and visible value with a ${p.enLabel.toLowerCase()} attentional priority. It is often described as a pattern that may ${p.enFocus}. This is not a diagnosis, and diligence, status, attractiveness, or one successful act cannot establish it. Ask whether attention repeatedly lands first on ${p.enFirst}, then organizes results and presentation around that information.\n\n“Instinct” is a traditional term here, not an independently established biological mechanism. A subtype is not a new personality outside Type 3. Work demands, scarcity, cultural norms, and relationship stages can create similar behavior, so supporting signals, counterexamples, and alternative explanations all belong in the review.`),
    section("model", "Combining a proposed core strategy with an attentional priority", `The Type 3 hypothesis describes a value-confirmation strategy: read success criteria, increase efficiency, adjust presentation, and pursue a recognizable result. The ${p.enLabel} subtype adds a proposed first scan for ${p.enFirst}; the achievement motive may then unfold by trying to ${p.enFocus}. Core motive, attention order, and visible behavior are different layers and should not be treated as interchangeable evidence.\n\nReliable delivery, protecting position, or supporting a key partner can arise from duty, care, economic necessity, or learned skill. The subtype adds value only when the first-attention pattern repeats across contexts and continues to influence resources, conflict priorities, and recovery even as external requirements change.`),
    section("recognition", "Signals across resources, groups, close connection, risk, and recovery", `In resource situations, observe whether attention first scans ${p.enFirst} and what information is postponed. In groups, ask whether a result serves the actual task, belonging, or visible comparison. In a key connection, record whether presentation is adjusted to preserve investment and shared success. When risk appears, distinguish sensible planning from acceleration used to preserve value. During recovery, notice whether stopping is possible without producing another result.\n\nCounterexamples matter equally. Scarcity makes almost anyone resource-focused; a public role heightens status awareness; a new bond intensifies one-to-one attention. When the pattern tracks the environment closely, prefer the environmental explanation.`),
    section("context", "At work, in relationships, while learning, and under pressure", `At work, this pattern may convert an abstract goal into a path that can ${p.enFocus}, while actively managing visibility and credibility. In relationships, care may be expressed through completion, improvement, or a shared result, while needs that are not yet polished remain harder to show. Learning may favor measurable progress and feedback that transfers into practical achievement.\n\nUnder pressure the characteristic risk is ${p.enRisk}. That narrowing is neither inevitable nor a prediction of career performance or relationship outcome. Persistent distress, sleep disruption, or impaired functioning requires appropriate support; a subtype narrative must not replace health assessment or contextual problem solving.`),
    section("strengths", "Potential resources when intensity and cost fit the situation", `${p.enLabel} attention may help Type 3 notice ${p.enFirst} early and turn vague success language into operational constraints. This can support prioritization, stakeholder communication, preparation, collaboration rhythm, and result review. The resource is visible in behavior: exposing risk early, making contribution and responsibility traceable, checking capacity before commitment, and adjusting when facts change.\n\nMore is not automatically better. When performance hides cost, bypasses consent, or serves image alone, it is no longer adaptive. Review result quality, process transparency, safety of refusal, and recovery capacity. These observable criteria are more useful than a flattering subtype label.`),
    section("blindspots", "What a narrowed attentional field may omit", `The central blind spot is not lack of action but ${p.enRisk}. A single priority can reduce the weight of bodily information, group realities, or needs outside a key connection, turning short-term success into a misleading proxy for overall health. Once results and value are fused, admitting limits can feel like losing position, so acceleration continues after usefulness declines.\n\nIf changing rewards, role, or resources makes the pattern recede, context explains more than subtype. Stop using the label if it rationalizes control, ignores consent, or treats collaborators as instruments of success. Return to boundaries, responsibility, and actual impact.`),
    section("compare", `${p.enLabel} compared with ${others}`, `| Dimension | Self-Preservation | Social | One-to-One |\n|---|---|---|---|\n| First attention | Resources, capacity, delivery | Role, status, visible contribution | Key person, attraction, shared success |\n| Route to security | Reliable work and preparation | Recognition and legible position | Mutual investment and distinctive connection |\n| Pressure compensation | More work and self-sufficiency | More display and adaptation | More image shaping and bond intensity |\n| Typical omission | Rest and asking for help | Private preference and invisible contribution | Wider support and independent needs |\n| Recovery entry | Bodily care without performance | Participation outside ranking | Lower intensity and restore diverse information |\n\nThese columns are observation prompts, not mutually exclusive boxes. Context can activate all three priorities; investigate which tends to arrive first and is hardest to release over time.`),
    section("mistype", "Countertype language, adjacent cores, and environmental demands", `Some traditions call certain Type 3 subtypes “countertypes” to explain why visible behavior may differ from a Type 3 stereotype. That is a tradition-specific interpretation, not a separately validated category. The Type 3 ${p.enLabel} pattern may resemble Type 2 or Type 4 with the same instinct, but the Type 3 hypothesis still centers how results confirm value.\n\nDo not infer subtype from occupation, wealth, social rank, appearance, relationship status, or sexuality. Use a counterfactual: if public evaluation, resource pressure, or the key person were removed, would the same attention order remain? When behavior changes sensibly with context, retain “environmental effect” rather than forcing a subtype.`),
    section("growth", "A seven-day attention-rebalancing experiment", `Each day choose one important event and record the first cue noticed, omitted information, expected evaluation, actual action, immediate benefit, and delayed cost. Add the Type 3 question: “Was this effective performance, genuine preference, or both?” On day seven review the record rather than relying on label familiarity.\n\nDeliberately complete one small action associated with each of the other priorities: bodily or resource care not tied to performance; group participation without competing for position; and a rejectable key-person conversation that does not use shared success to prove value. Write predictions first and compare them with events afterward.`),
    section("evidence", "Evidence status and defensible claim limits", `Hook and colleagues' systematic review found mixed evidence for the Enneagram overall and limited support for secondary propositions such as instinctual subtypes. A Turkish-sample subtype inventory study provides exploratory results within a constrained sample and measurement context; it cannot validate this page or establish cross-cultural universality. Competitor pages are used only to benchmark terminology, user questions, and the three-part information architecture.\n\nDo not use this subtype for diagnosis, treatment, hiring, ability judgment, career prediction, or compatibility promises. The hypothesis is worth retaining only when it remains falsifiable, improves choices, and respects consent and boundaries.`),
    section("next_steps", "Read the core first, then make a matched three-way comparison", `Use the Type 3 core page to determine whether achievement and visible value organize behavior over time. Then read the other two subtype pages using the same table. Sort the seven-day evidence into support, disconfirmation, and unknown, preserving events that are adequately explained by environmental demands. Do not rank the three priorities as better or worse.\n\nFor the next cycle change one variable only: reduce display, add bodily data, widen feedback sources, or replace a shared-success signal with a direct request. Record a prediction and the observed result. An assessment begins review; when attention order is unstable or alternative explanations are stronger, “not yet determined” is appropriate.`),
  ];
  return common("en", "instinctual_subtype", code, `enneagram/type-3/instincts/${instinct}`, title, `This pattern combines Type 3 achievement and visible value with ${p.enLabel.toLowerCase()} attention and may ${p.enFocus}. It is a reflection tool, not a diagnosis or fixed identity.`, sections, subtypeFaqEn(instinct,p), subtypeGeoEn(instinct,p), subtypeLinks("en",instinct,p), subtypeSources);
}

function wingFaqZh(code,p){return [
  {id:`distinguish-${code}-${p.zhOther}`,question:`怎样区分 ${code} 与 ${p.zhOther}？`,answer:`先确认共同的第 3 型成果与可见价值动机，再比较注意焦点、沟通、冲突、压力补偿和成长入口。${code} 更常围绕“${p.zhFocus}”组织行动，但需要跨情境证据。`,evidence_ids:["truity-wings-guide","hook-2021"]},
  {id:`adjacent-${code}`,question:`${code} 会变成${p.zhAdjacent}吗？`,answer:`不会。翼型传统只用相邻类型描述可能的表达影响，不意味着核心类型变成邻型，也不是两者各占一半。`,evidence_ids:["truity-wings-boundary"]},
  {id:`context-${code}`,question:"翼的表达会随情境变化吗？",answer:"行为会受角色、奖励、压力和文化影响；这正是为什么要记录多个情境与反例，而不能用一次表现定型。",evidence_ids:["hook-2021"]},
  {id:`pressure-${code}`,question:`${code} 在压力下可能出现什么？`,answer:`可能出现${p.zhRisk}，但它不是必然结果或健康诊断。应同时检查任务设计、资源、睡眠和支持。`,evidence_ids:["truity-wings-growth"]},
  {id:`evidence-${code}`,question:"翼型有充分科学证据吗？",answer:"现有系统综述显示九型人格证据混合，对翼型等次级命题支持有限。本页只把翼型当作可反驳的观察假设。",evidence_ids:["hook-2021"]},
]}
function wingFaqEn(code,p){return [
  {id:`distinguish-${code}-${p.enOther}`,question:`How can I distinguish ${code} from ${p.enOther}?`,answer:`Confirm the shared Type 3 concern with achievement and visible value, then compare attention, communication, conflict, pressure compensation, and growth entry. ${code} more often organizes action around ${p.enFocus}, but repeated contextual evidence is required.`,evidence_ids:["truity-wings-guide","hook-2021"]},
  {id:`adjacent-${code}`,question:`Does ${code} become ${p.enAdjacent}?`,answer:`No. Wing tradition uses an adjacent type to describe possible expressive influence; it does not replace the core or create a fifty-fifty blend.`,evidence_ids:["truity-wings-boundary"]},
  {id:`context-${code}`,question:"Can wing expression change by context?",answer:"Behavior changes with role, reward, pressure, and culture. That is why multiple contexts and counterexamples are needed instead of typing from one performance.",evidence_ids:["hook-2021"]},
  {id:`pressure-${code}`,question:`What may ${code} show under pressure?`,answer:`A possible pattern is ${p.enRisk}. This is neither inevitable nor a health diagnosis; task design, resources, sleep, and support also matter.`,evidence_ids:["truity-wings-growth"]},
  {id:`evidence-${code}`,question:"Are Enneagram wings strongly supported by research?",answer:"The systematic-review evidence is mixed, with limited support for secondary propositions such as wings. This page treats a wing as a falsifiable observation hypothesis.",evidence_ids:["hook-2021"]},
]}
function subtypeFaqZh(instinct,p){return [
  {id:`meaning-3-${p.code}`,question:`第 3 型·${p.zhLabel}本能副型是什么意思？`,answer:`它把第 3 型的成果与价值确认动机，同${p.zhLabel}注意优先级组合，常见假设是${p.zhFocus}。它不是诊断或固定身份。`,evidence_ids:["truity-subtypes-overview","hook-2021"]},
  {id:`compare-3-${p.code}`,question:`它与另外两个第 3 型副型有什么区别？`,answer:`以相同维度比较首先注意、获得安全或归属的路径、压力补偿、常见遗漏和恢复入口，不要只看职业或社交风格。`,evidence_ids:["truity-subtypes-heart"]},
  {id:`countertype-3-${p.code}`,question:"“反型”是独立人格类型吗？",answer:"不是已独立验证的类别；它是部分九型传统用于解释表面差异的术语。",evidence_ids:["truity-countertypes","hook-2021"]},
  {id:`context-3-${p.code}`,question:"本能副型会随情境改变吗？",answer:"注意和行为会受资源、角色、文化与关系阶段影响。应记录长期第一注意点，同时保留环境解释。",evidence_ids:["truity-subtypes-disagreement"]},
  {id:`evidence-3-${p.code}`,question:"27 个副型有充分科学证据吗？",answer:"当前证据不足以把 27 个副型当作独立且普遍有效的人格类别；相关研究有样本与测量限制。",evidence_ids:["hook-2021","turkish-subtype-inventory"]},
]}
function subtypeFaqEn(instinct,p){return [
  {id:`meaning-3-${p.code}`,question:`What does the Type 3 ${p.enLabel} subtype mean?`,answer:`It combines the proposed Type 3 achievement-and-value motive with ${p.enLabel.toLowerCase()} attention and may ${p.enFocus}. It is not a diagnosis or fixed identity.`,evidence_ids:["truity-subtypes-overview","hook-2021"]},
  {id:`compare-3-${p.code}`,question:"How does it differ from the other two Type 3 subtypes?",answer:"Compare first attention, route to security or belonging, pressure compensation, omitted information, and recovery using matched dimensions, not occupation or social style.",evidence_ids:["truity-subtypes-heart"]},
  {id:`countertype-3-${p.code}`,question:"Is a countertype a separate personality type?",answer:"No independently validated category has been established; countertype is a term used by some Enneagram traditions to explain surface differences.",evidence_ids:["truity-countertypes","hook-2021"]},
  {id:`context-3-${p.code}`,question:"Can instinctual subtype expression change by context?",answer:"Attention and behavior respond to resources, roles, culture, and relationship stage. Track the long-term first-attention pattern while retaining environmental explanations.",evidence_ids:["truity-subtypes-disagreement"]},
  {id:`evidence-3-${p.code}`,question:"Are the 27 subtypes strongly supported by research?",answer:"Evidence is insufficient to treat 27 subtypes as independently established, universally valid personality categories; existing studies have sampling and measurement limits.",evidence_ids:["hook-2021","turkish-subtype-inventory"]},
]}

const geo=(kind,question,answer,section_key)=>({kind,question,answer,section_key});
const wingGeoZh=(code,p)=>[geo("definition",`${code} 是什么？`,`${code} 以第 3 型成果与可见价值为核心，用${p.zhAdjacent}描述${p.zhStyle}的表达。它是观察假设。`,"quick_answer"),geo("comparison",`${code} 与 ${p.zhOther} 的关键区别？`,`两者共享第 3 型核心；${code} 更常通过“${p.zhFocus}”组织成果。需要同维度、跨情境比较。`,"compare"),geo("practice",`如何观察 ${code}？`,`连续七天分开记录成功标准、真实偏好、行动和结果，并主动寻找环境反例。`,"growth")];
const wingGeoEn=(code,p)=>[geo("definition",`What is ${code}?`,`${code} uses Type 3 achievement and visible value as the proposed core, with ${p.enAdjacent} describing a ${p.enStyle} expression.`,"quick_answer"),geo("comparison",`What distinguishes ${code} from ${p.enOther}?`,`Both share the proposed Type 3 core; ${code} more often organizes results through ${p.enFocus}. Use matched, cross-context comparison.`,"compare"),geo("practice",`How can I observe ${code}?`,`For seven days record success criteria, genuine preference, action, and result separately, while seeking environmental counterexamples.`,"growth")];
const subtypeGeoZh=(instinct,p)=>[geo("definition",`第 3 型·${p.zhLabel}副型是什么？`,`它把第 3 型成果与价值确认动机同${p.zhLabel}注意结合，常见假设是${p.zhFocus}。`,"quick_answer"),geo("comparison",`三个第 3 型副型如何区分？`,`比较首先注意、安全路径、压力补偿、遗漏信息和恢复入口，不按职业或外表定型。`,"compare"),geo("practice",`如何观察${p.zhLabel}注意？`,`七天记录最先注意、忽略信息、成本和结果，再补做另外两种注意的一项行动。`,"growth")];
const subtypeGeoEn=(instinct,p)=>[geo("definition",`What is the Type 3 ${p.enLabel} subtype?`,`It combines Type 3 achievement and visible value with ${p.enLabel.toLowerCase()} attention and may ${p.enFocus}.`,"quick_answer"),geo("comparison","How do the three Type 3 subtypes differ?","Compare first attention, security route, pressure compensation, omitted information, and recovery rather than occupation or appearance.","compare"),geo("practice",`How can I observe ${p.enLabel.toLowerCase()} attention?`,`For seven days record what appeared first, what was omitted, cost, and result; then try one action from each other priority.`,"growth")];

function wingLinks(lang,code,p){const pre=lang==="zh"?"/zh":"/en"; return [{label:lang==="zh"?"第 3 型核心人格":"Core Type 3",href:`${pre}/personality/enneagram/type-3`,relationship:"core_type"},{label:p[lang==="zh"?"zhOther":"enOther"],href:`${pre}/personality/enneagram/wings/${p.enOther}`,relationship:"sibling_wing"},{label:lang==="zh"?p.zhAdjacent:p.enAdjacent,href:`${pre}/personality/enneagram/type-${p.adjacent}`,relationship:"adjacent_core_type"},{label:lang==="zh"?"九型人格指南":"Enneagram guide",href:`${pre}/personality/enneagram`,relationship:"hub"}]}
function subtypeLinks(lang,instinct,p){const pre=lang==="zh"?"/zh":"/en"; return [{label:lang==="zh"?"第 3 型核心人格":"Core Type 3",href:`${pre}/personality/enneagram/type-3`,relationship:"core_type"},...p.siblings.map(x=>({label:lang==="zh"?`第 3 型·${subtypeProfiles[x].zhLabel}`:`Type 3 ${subtypeProfiles[x].enLabel}`,href:`${pre}/personality/enneagram/type-3/instincts/${x}`,relationship:"sibling_subtype"})),{label:lang==="zh"?"九型人格指南":"Enneagram guide",href:`${pre}/personality/enneagram`,relationship:"hub"}]}

function writeAsset(relative, asset) {
  if (asset.sections.map((x)=>x.key).join("|") !== sectionKeys.join("|")) throw new Error(`section contract: ${relative}`);
  const path=resolve(root,relative); mkdirSync(dirname(path),{recursive:true}); writeFileSync(path,`${JSON.stringify(asset,null,2)}\n`);
}

for (const [code,p] of Object.entries(wingProfiles)) {
  writeAsset(`assets/wings/zh-CN/${code}.json`,wingZh(code,p));
  writeAsset(`assets/wings/en/${code}.json`,wingEn(code,p));
}
for (const [instinct,p] of Object.entries(subtypeProfiles)) {
  writeAsset(`assets/instinctual-subtypes/zh-CN/type-3-${instinct}.json`,subtypeZh(instinct,p));
  writeAsset(`assets/instinctual-subtypes/en/type-3-${instinct}.json`,subtypeEn(instinct,p));
}
console.log(JSON.stringify({task_id:taskId,status:"drafted",assets:10},null,2));
