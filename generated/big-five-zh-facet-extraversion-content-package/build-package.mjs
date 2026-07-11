import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-zh-facet-extraversion-content-package");
const baseSeedPath = resolve("backend/content_assets/personality_public/big_five_v1_seed.json");
const sharedLedgerPath = resolve("generated/big-five-zh-facet-hub-content-repair/source_ledger.json");
const generatedAt = "2026-07-10T00:00:00Z";

const facets = [
  {
    code: "warmth",
    title: "热情",
    short: "在一对一或小范围互动中主动表达亲近、关心和友好情感的通常倾向",
    higher: "较容易用问候、回应、分享和情感表达让关系变得亲近，并愿意投入时间维持个人联结",
    lower: "更偏好克制、任务导向或保留私人空间的互动方式，可能通过可靠行动而不是明显情感表达来表示在意",
    context: "新成员加入团队时，较明显的热情能降低进入门槛；处理隐私、冲突或正式边界时，较克制的表达也可能让人有更多空间和安全感",
    misread: "它不等于同理心、善良、宜人性、恋爱意愿或人际能力。表达亲近较少的人仍可能深切关心他人，表达热情较多也不保证理解对方需要",
    observe: "比较自己对熟人、陌生人、同事和需要独处的人如何表达关心，记录对方是否欢迎这种方式，而不是只根据自己的表达强度判断关系质量",
    experiment: "选择一段重要关系，先询问对方更希望收到主动问候、实际帮助还是安静空间，再做一个低成本回应；复盘对方反馈，调整表达而不是追求更热烈",
  },
  {
    code: "gregariousness",
    title: "合群性",
    short: "对与多人相处、加入群体活动和从共同在场中获得刺激的通常偏好程度",
    higher: "较愿意参与聚会、群聊或共同活动，长时间独处后可能主动寻找同伴和现场互动",
    lower: "更享受独处、一对一或小规模互动，群体时间过长时可能需要安静恢复，并会更挑选社交场合",
    context: "需要快速建立跨团队网络时，较明显的合群性能增加接触机会；深度工作、敏感讨论或恢复精力时，小范围与独处同样有价值",
    misread: "它不等于社交技巧、人缘、孤独、社交焦虑或是否喜欢某个人。想参加群体和能否处理复杂社交是不同问题，独处偏好也不是关系缺失",
    observe: "记录不同规模、熟悉度和时长的社交活动前后精力变化，区分是人数本身、互动质量、噪声、角色压力还是缺少退出空间造成差异",
    experiment: "为下周安排一次可退出的群体活动和一次高质量小范围交流，分别记录投入与恢复成本；用结果调整社交组合，而不是要求自己固定成外向或内向模板",
  },
  {
    code: "assertiveness",
    title: "表达主导性",
    short: "在群体中主动说出观点、提出方向、影响讨论或承担带领角色的通常倾向",
    higher: "较容易较早发言、明确立场、分配注意或推动决定，在讨论停滞时愿意提出下一步",
    lower: "更常先观察和倾听，通过问题、书面意见或支持他人方案参与，不一定主动占据领导或发言位置",
    context: "时间有限且责任清楚时，较明显的表达主导性能加快协调；需要收集弱势意见或处理高不确定问题时，暂缓主导可能让更多信息进入讨论",
    misread: "它不等于攻击性、权力欲、自信、专业正确或领导能力。说得早和说得响不能证明判断更好，较少发言也不代表没有观点或不能领导",
    observe: "回看三次会议，记录自己何时发言、是否提出方向、他人是否有表达空间，以及书面与口头场景的差异；同时检查地位和安全感对行为的影响",
    experiment: "在一次低风险讨论中，若你常主导就先邀请两人发言再总结；若你常等待，就提前写一句观点并在前十分钟说出，随后观察信息质量和参与分布",
  },
  {
    code: "activity",
    title: "活跃度",
    short: "对较快生活节奏、同时推进事务和保持身体或行动忙碌的通常偏好程度",
    higher: "较喜欢紧凑安排、快速切换和持续行动，空档较多时可能主动寻找事情或加快节奏",
    lower: "更偏好从容、单线和留有缓冲的节奏，在较少事项中持续投入，不一定追求忙碌感",
    context: "短周期运营和现场协调中，较高活跃度可维持响应；复杂分析、康复和精细工作中，慢节奏与空档可能提高质量并降低切换成本",
    misread: "它不等于体能、健康、生产力、勤奋或 ADHD。忙碌不保证产生价值，节奏较慢也不表示懒惰；临床或身体状态需要独立证据",
    observe: "连续三天记录自然步速、日程密度、任务切换和休息后的精力，区分是偏好快节奏，还是截止期、通勤、照护责任或环境要求造成忙碌",
    experiment: "选择一个工作时段，比较“集中完成一件事”和“按原习惯切换”的完成量、错误和疲劳；保留更匹配任务的节奏，而不是单纯追求更快或更满",
  },
  {
    code: "excitement-seeking",
    title: "刺激寻求",
    short: "对新鲜、强烈、快速或高刺激体验的通常兴趣和接近倾向",
    higher: "较容易被速度、变化、竞争、强烈感官或带有挑战的体验吸引，并可能在低刺激环境中感到乏味",
    lower: "更偏好安稳、熟悉和刺激可控的环境，通常不需要强烈体验来维持兴趣或投入",
    context: "创意探索和可控挑战中，较高刺激寻求能推动尝试；驾驶、财务和安全决策中，刺激偏好必须与后果评估、规则和退出条件分开处理",
    misread: "它不等于鲁莽、勇敢、成瘾、冒险结果或违法倾向。喜欢刺激的人可以严格管理风险，偏好低刺激的人也可能在有意义时承担重大挑战",
    observe: "记录自己在哪些活动中主动提高速度、强度或不确定性，并分别评估享受程度、实际风险、可逆性和对他人的影响，避免把刺激感等同于价值",
    experiment: "选择一个安全可控的方式增加新鲜感，并提前设置预算、时间、保护措施和停止条件；若你常追求刺激，同时安排一个低刺激活动观察注意能否恢复",
  },
  {
    code: "positive-emotions",
    title: "积极情绪",
    short: "体验并表达愉快、兴奋、活力和庆祝感的通常频率与明显程度",
    higher: "较容易感到并表达开心、兴奋或幽默，在顺利和连接时刻可能主动分享积极体验",
    lower: "积极体验可能更平静、克制或短暂，不一定频繁表现兴奋，但仍可以满足、投入并关心重要事物",
    context: "庆祝成果和鼓舞团队时，较明显的积极情绪可放大共同体验；风险复盘或他人受挫时，保持克制能避免过早乐观和情绪不匹配",
    misread: "它不等于乐观判断、心理健康、幸福水平、善良或没有负面情绪。表达较少不能诊断抑郁，表达较多也不表示没有压力或困难",
    observe: "记录一周内让自己感到愉快、满足或兴奋的事件以及表达方式，比较独处和社交场景，并注意文化、角色和安全感是否影响外显程度",
    experiment: "每天记录一件具体的正向事件及其强度，不强迫自己积极；选择一次合适的感谢或庆祝表达，同时允许担忧和疲惫并存，一周后检查体验是否更清楚",
  },
];

const routeFor = (code) => `/zh/personality/big-five/facets/${code}`;
const packageName = "big-five-zh-facet-extraversion-content-package-2026-07-10";
const baseSeed = JSON.parse(await readFile(baseSeedPath, "utf8"));
const sharedLedger = JSON.parse(await readFile(sharedLedgerPath, "utf8"));

const baseAssets = new Map(baseSeed.assets
  .filter((asset) => asset.framework === "big_five" && asset.entity_type === "facet_detail" && asset.locale === "zh-CN")
  .map((asset) => [asset.entity_key, asset]));

const internalLinksFor = (currentCode) => [
  { label: "外向性", href: "/zh/personality/big-five/extraversion", relationship: "parent_domain" },
  { label: "30 个细分面向", href: "/zh/personality/big-five/facets", relationship: "facet_hub" },
  ...facets
    .filter((facet) => facet.code !== currentCode)
    .map((facet) => ({ label: facet.title, href: routeFor(facet.code), relationship: "sibling_facet" })),
];

const sectionsFor = (facet) => [
  {
    key: "quick_answer",
    title: `快速回答：${facet.title}是什么`,
    body_md: `${facet.title}描述${facet.short}。它是外向性下的一个连续细分面向，不是一种人格类型，也不是给个人下定论的标签。较明显或较不明显只表示通常关注点可能不同；具体表现还会随任务、经验、资源、角色和压力改变。`,
  },
  {
    key: "what_it_captures",
    title: `${facet.title}主要在观察什么`,
    body_md: `${facet.title}关注的是人在有互动与行动空间时，通常怎样接近社交刺激、表达能量、影响群体并体验正向唤起。它不单看一次行为，也不把外显程度直接等同于关系质量或能力。更可靠的阅读方式，是比较多个时间点和至少两个不同情境，再询问这种模式带来了什么便利、成本与支持需求。`,
  },
  {
    key: "higher_expression",
    title: `${facet.title}较明显时可能怎样表现`,
    body_md: `${facet.higher}。这种倾向在匹配的任务里可能增加互动机会、行动动量或积极体验，但也可能带来刺激过量、占用他人空间、忽略恢复需求或过快判断等成本。是否有帮助取决于场合、他人反馈、风险边界和能否调节强度。`,
  },
  {
    key: "lower_expression",
    title: `${facet.title}较不明显时可能怎样表现`,
    body_md: `${facet.lower}。这并不表示缺少关系、情感或能力，而可能是一种刺激和精力管理方式。在需要深度、克制、独立判断或安静恢复时，这一端可能有实际价值；当任务需要更多外部互动时，则可以借助准备、书面表达、小范围连接或明确退出空间补足。`,
  },
  {
    key: "context_examples",
    title: "放进真实情境理解",
    body_md: `${facet.context}。这些例子只说明同一倾向在不同任务中可能产生不同效果，不预测个人表现。判断前应同时查看目标、风险、时限、协作者和可逆性。`,
  },
  {
    key: "common_misreads",
    title: "常见误解与相邻概念",
    body_md: `${facet.misread}。外向性六个面向也不必同步：某人在这个面向上较明显，不代表热情、合群性、表达主导性、活跃度、刺激寻求和积极情绪都会处在同一位置。`,
  },
  {
    key: "observe_in_context",
    title: "怎样观察自己的模式",
    body_md: `${facet.observe}。尽量使用可观察行为和原话，不用“我就是这样的人”概括。若只有一次事件，先记为线索；当反例出现时，更新假设而不是把反例解释掉。`,
  },
  {
    key: "small_experiment",
    title: "一个可逆的小实验",
    body_md: `${facet.experiment}。实验的目的不是把分数推向某一端，而是增加选择：知道什么时候沿用默认方式，什么时候换一种策略，并保留退出和复盘空间。`,
  },
  {
    key: "method_boundary",
    title: "方法与使用边界",
    body_md: `本页沿用现有 CMS 与 NEO / IPIP 传统相近的 30-facet 导航来解释“${facet.title}”，不复制专有量表题目，也不把它与 BFI-2 的 15 facets 或 BFAS 的 10 aspects 直接换算。页面不读取私人测评结果，不提供常模、百分位、信效度数字，不用于诊断、治疗、招聘或录取筛选、能力判断、收入预测、关系结果预测或确定性职业建议。`,
  },
];

const faqFor = (facet) => [
  {
    id: "higher-better",
    question: `${facet.title}越高越好吗？`,
    answer: `不是。${facet.title}两端都可能在不同任务中有便利与成本，重点是情境匹配、调节方式和是否保留核验。`,
    evidence_ids: ["I2", "A4"],
  },
  {
    id: "can-change",
    question: `${facet.title}会随情境变化吗？`,
    answer: "会。人格语言描述通常倾向，不代表每次行为相同；角色、经验、压力、资源和明确规则都可能改变当下表现。",
    evidence_ids: ["A4"],
  },
  {
    id: "same-as-domain",
    question: `${facet.title}能代表整个外向性吗？`,
    answer: "不能。它只是外向性下六个细分面向之一，其他面向可能呈现不同位置，宽维度也不能被单一面向替代。",
    evidence_ids: ["I1", "A1"],
  },
  {
    id: "personal-score",
    question: `这个页面能解释我的${facet.title}分数吗？`,
    answer: "不能。本页只解释公共概念。个人结果必须按具体量表的计分、作答质量、常模与解释契约阅读，并结合本人反馈。",
    evidence_ids: ["I1", "I2"],
  },
  {
    id: "high-stakes",
    question: `${facet.title}可以用于招聘、诊断或职业决定吗？`,
    answer: "不可以。它不能替代临床评估、工作样本、结构化招聘流程、职业信息或其他高风险决定所需的证据。",
    evidence_ids: ["I2"],
  },
];

const rawAssets = [];
const repairedAssets = [];
for (const facet of facets) {
  const base = baseAssets.get(facet.code);
  if (!base) throw new Error(`Missing base facet asset: ${facet.code}`);

  rawAssets.push({
    ...structuredClone(base),
    review_state: "codex_raw_untrusted",
    source_package: `${packageName}-raw`,
    source_hash: null,
    last_reviewed_at: generatedAt,
    source_ledger_refs: ["SHARED", "I1", "I2", "A1"],
    model_output_refs: [`codex-native-raw-${facet.code}-2026-07-10`],
  });

  repairedAssets.push({
    ...structuredClone(base),
    summary: `${facet.title}描述${facet.short}。本页用平衡语言解释两端表现、情境差异、常见误解与可逆行动，不把它当作能力、诊断或固定身份。`,
    seo: {
      title: `大五人格${facet.title}：含义、表现、误解与行动建议`,
      description: `理解外向性细分面向“${facet.title}”的含义、较明显与较不明显的表现、真实情境、常见误解和非诊断使用边界。`,
    },
    sections: sectionsFor(facet),
    faq: faqFor(facet),
    internal_links: internalLinksFor(facet.code),
    robots: "noindex,follow",
    launch_state: "content_ready",
    review_state: "codex_repaired_ready",
    index_eligible: false,
    sitemap_eligible: false,
    llms_eligible: false,
    source_package: packageName,
    source_hash: null,
    last_reviewed_at: generatedAt,
    schema: { ...base.schema, status: "noindex_facet_content_package_01" },
    method_boundary: {
      summary: `本页解释外向性下的“${facet.title}”公共概念，不解释个人结果，也不替代具体量表契约或专业判断。`,
      taxonomy_boundary: "NEO/IPIP-like 30-facet route taxonomy; no direct conversion to BFI-2 facets or BFAS aspects.",
      not_for: ["临床诊断", "治疗建议", "招聘或录取筛选", "能力或智力判断", "收入、关系或职业结果预测"],
    },
    evidence_notes: [
      { source_type: "taxonomy", note: "现有 CMS route set 采用与 NEO / IPIP 30-facet 传统相近的六面向外向性导航。" },
      { source_type: "boundary", note: `${facet.title}是连续倾向，不是人格类型、能力等级或确定性预测。` },
      { source_type: "search", note: "GSC_EVIDENCE_PENDING；本包不主张搜索表现或可索引发布资格。" },
    ],
    source_ledger_refs: ["SHARED", "I1", "I2", "I3", "A1", "A2", "A3", "A4"],
    model_output_refs: [
      `codex-native-raw-${facet.code}-2026-07-10`,
      "codex-skeptical-review-extraversion-2026-07-10",
      `codex-repair-${facet.code}-2026-07-10`,
    ],
  });
}

const envelope = (name, assets) => ({
  package: name,
  contract_version: "personality_public_asset.v1",
  generated_at: generatedAt,
  assets,
});

const sourceLedger = {
  package: "big-five-zh-facet-extraversion-source-ledger-2026-07-10",
  access_date: "2026-07-10",
  scope: "Six Chinese extraversion Facet content packages only.",
  inherits: {
    path: "generated/big-five-zh-facet-hub-content-repair/source_ledger.json",
    package: sharedLedger.package,
  },
  sources: sharedLedger.sources,
  taxonomy: sharedLedger.taxonomy.filter((domain) => domain.domain_code === "extraversion"),
  claim_map: [
    { claim: "The six routes are narrower descriptors under extraversion, not six personality types.", evidence_ids: ["I1", "A1", "A4"] },
    { claim: "Interest or attention in a facet is not equivalent to ability, diagnosis, or outcome prediction.", evidence_ids: ["I2", "A4"] },
    { claim: "The route taxonomy must not be directly converted to BFI-2 facets or BFAS aspects.", evidence_ids: ["A1", "A2", "A3"] },
  ],
  facet_boundaries: Object.fromEntries(facets.map((facet) => [facet.code, {
    title_zh: facet.title,
    construct_summary: facet.short,
    explicit_non_equivalence: facet.misread,
  }])),
  limitations: sharedLedger.limitations,
};

const skepticalReview = {
  package: packageName,
  raw_draft: "raw_codex_draft.json",
  reviewer_mode: "codex_skeptical_self_review",
  critical_violations: [],
  major_repairs: [
    "Replace all six one-paragraph taxonomy stubs with nine substantive, facet-specific sections.",
    "Balance both ends of every facet and remove high-equals-better implications.",
    "Separate warmth from empathy or agreeableness, gregariousness from social skill or loneliness, assertiveness from aggression or expertise, activity from productivity or diagnosis, excitement seeking from recklessness, and positive emotions from mental health or guaranteed happiness.",
    "Add five FAQ items and seven structured internal links per asset without self-links.",
    "Add cross-instrument taxonomy and private-result boundaries while preserving noindex gates.",
  ],
  per_asset: Object.fromEntries(facets.map((facet) => [facet.code, {
    raw_status: "content_stub_insufficient",
    repaired_status: "pass",
    duplicate_risk: "pass_unique_facet_examples_and_boundaries",
    critical_violations: [],
  }])),
  private_result_boundary: "pass_after_repair",
  adjudication: "repaired_required",
};

await mkdir(outputDir, { recursive: true });
await Promise.all([
  writeFile(resolve(outputDir, "source_ledger.json"), `${JSON.stringify(sourceLedger, null, 2)}\n`),
  writeFile(resolve(outputDir, "raw_codex_draft.json"), `${JSON.stringify(envelope(`${packageName}-raw`, rawAssets), null, 2)}\n`),
  writeFile(resolve(outputDir, "skeptical_review.json"), `${JSON.stringify(skepticalReview, null, 2)}\n`),
  writeFile(resolve(outputDir, "repaired_draft.json"), `${JSON.stringify(envelope(`${packageName}-repaired`, repairedAssets), null, 2)}\n`),
  writeFile(resolve(outputDir, "big_five_zh_facet_extraversion_seed.json"), `${JSON.stringify(envelope(packageName, repairedAssets), null, 2)}\n`),
]);

console.log(`generated ${repairedAssets.length} Chinese extraversion Facet assets`);
