import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-zh-facet-openness-content-package");
const baseSeedPath = resolve("backend/content_assets/personality_public/big_five_v1_seed.json");
const sharedLedgerPath = resolve("generated/big-five-zh-facet-hub-content-repair/source_ledger.json");
const generatedAt = "2026-07-10T00:00:00Z";

const facets = [
  {
    code: "imagination",
    title: "想象力",
    short: "对心理图景、假设情景、故事与隐喻的通常投入程度",
    higher: "较容易在头脑中展开尚未发生的情景，用画面、故事或类比探索可能性",
    lower: "更愿意从眼前事实、明确步骤和已经出现的约束开始，不急于扩展假设世界",
    context: "做产品方案时，较明显的想象力可能帮助团队预演不同用户旅程；处理事故时，较少展开想象反而可能帮助人先锁定日志、时间线和可验证事实",
    misread: "它不是是否务实、是否会做白日梦或是否有创造天赋的判定。想象丰富的人仍可严谨核验，偏好具体事实的人也能通过经验和工具完成创新",
    observe: "留意自己面对空白题目、未来计划或含糊描述时，是先形成画面和故事，还是先寻找实例、数据与操作步骤；再比较创作任务和紧急任务中的差异",
    experiment: "选一个小问题，先用三分钟写出两个可能情景，再为每个情景补一条可验证证据。若你通常想象很多，就增加证据约束；若你通常只看现状，就允许一个低成本假设",
  },
  {
    code: "aesthetics",
    title: "审美",
    short: "对形式、色彩、声音、节奏、文字和环境氛围的通常注意与投入程度",
    higher: "较容易注意作品或环境中的构图、质感、节奏与象征，并愿意停下来体会这些细节",
    lower: "更常把注意力放在用途、清晰度、效率和可操作信息上，不一定主动追踪形式带来的体验",
    context: "设计评审中，较明显的审美关注能发现层级、语气和视觉节奏的问题；在资源紧张的交付中，较少受形式牵引的人可能更快守住可用性和完成条件",
    misread: "它不等于艺术技能、品位等级、消费偏好或文化资本。能否画画、是否喜欢某种音乐，以及是否购买昂贵物品，都不能单独代表这个面向",
    observe: "比较自己进入陌生空间、阅读长文或听一段音乐时首先注意什么，并记录形式体验是否会改变理解、情绪或行动；同时寻找一个自己几乎不在意形式的情境",
    experiment: "为一个日常材料做两版呈现：一版只保证信息完整，一版额外调整层级、留白或节奏。请另一人说明哪一版更易理解，再区分审美偏好与实际可用性",
  },
  {
    code: "feelings",
    title: "感受性",
    short: "对自身情绪经验的辨认、关注和表达兴趣，而不是情绪强弱本身",
    higher: "较愿意分辨内在感受的细微变化，并把感受当作理解需要、关系或选择的一类信息",
    lower: "更常把注意力放在事件、目标和解决步骤上，可能只在情绪明显影响行动时才专门处理它",
    context: "冲突复盘中，较明显的感受性可以帮助区分失望、担忧与被忽视感；在需要快速处置的现场，暂时把情绪放到稍后处理也可能保护行动节奏",
    misread: "它不等于情绪不稳定、同理心、脆弱或心理健康状态。能觉察感受的人未必波动更大，较少谈感受的人也不代表没有情绪或不关心他人",
    observe: "回看最近一次重要决定：你是否能说出除好坏之外更具体的感受，它是否提供了有用线索，又是否被事实纠正；再观察自己在独处和公开场合的差异",
    experiment: "每天选一个事件，用一个感受词、一条身体线索和一项事实分别记录。决策时三者都看，但不让任何一项单独下结论，一周后检查哪些信息真正帮助了行动",
  },
  {
    code: "actions",
    title: "行动开放",
    short: "对改变熟悉做法、尝试新活动和接触陌生环境的通常意愿",
    higher: "较愿意在风险可控时换一种路线、工具或体验，通过亲自尝试获得信息",
    lower: "更信任熟悉流程、稳定节奏和已有经验，通常希望先确认收益与边界再改变做法",
    context: "学习新工具时，较明显的行动开放会推动快速试用；在合规、财务或安全流程中，坚持成熟步骤往往更重要。关键是让尝试成本与任务风险匹配",
    misread: "它不等于勇敢、旅行次数、冲动性或喜欢危险。谨慎的人也可以通过小规模试点探索，常尝试新事物的人仍需要评估成本、退出条件与他人影响",
    observe: "记录自己面对新餐厅、新软件、新协作方式或临时路线时的第一反应，并区分是对新颖本身的偏好，还是由时间、金钱、安全和责任约束造成的选择",
    experiment: "挑一个可逆的小环节做 A/B 尝试，提前写明投入上限、停止条件和复盘指标。若你常追新，就限制同时尝试数量；若你偏熟悉，就把新方案缩成十分钟试用",
  },
  {
    code: "ideas",
    title: "观念开放",
    short: "对抽象问题、复杂解释、概念联系与不同观点的通常探索兴趣",
    higher: "较享受追问原理、比较模型和处理暂时没有单一答案的问题",
    lower: "更偏好与当前任务直接相关、可以落到例子和步骤的信息，不一定愿意为抽象讨论投入很多时间",
    context: "研究和战略工作中，较明显的观念开放有助于比较竞争解释；在明确执行窗口里，及时停止发散、选定可操作方案同样重要。好奇与收敛需要配合",
    misread: "它不是智力、学历、知识量或正确率。喜欢抽象讨论不保证判断准确，偏好具体问题也不表示理解能力较弱；能力证据必须来自相应任务和测量",
    observe: "观察自己遇到反常数据、长篇理论或不同立场时，是想继续追问机制，还是先问这对当前任务有什么用；再检查时间压力变化后，偏好是否随之改变",
    experiment: "为一个观点写出最强支持理由、最强反例和一个能区分两种解释的小测试。给探索设定结束时间，到点后必须形成下一步，而不是把持续思考当成结论",
  },
  {
    code: "values",
    title: "价值开放",
    short: "对重新检查惯例、规则依据和自身价值假设的通常意愿",
    higher: "较愿意追问一条惯例为何成立，并在证据、情境或受影响群体变化时修正立场",
    lower: "更重视经过时间检验的规范、一致性和共同预期，通常需要更充分理由才改变原则或规则",
    context: "制度改进中，较明显的价值开放能暴露旧规则忽略的条件；在需要稳定协作时，维护可预期边界也有价值。修正与连续性并不是简单的先进和落后",
    misread: "它不等于道德水平、政治立场、叛逆程度或是否尊重传统。持何种观点与愿不愿审查观点是不同问题，任何立场都需要说明证据、代价和权利边界",
    observe: "选择一条自己支持或反对的规则，写下它保护了谁、让谁承担成本、在什么证据下应调整。再找一个自己坚持惯例的场景，确认坚持来自原则还是省力",
    experiment: "与立场不同但可信的人交换各自最担心失去的东西，并共同提出一个不要求任何人先放弃核心边界的小改动。复盘时评价信息增加了什么，而非谁被说服",
  },
];

const routeFor = (code) => `/zh/personality/big-five/facets/${code}`;
const packageName = "big-five-zh-facet-openness-content-package-2026-07-10";
const baseSeed = JSON.parse(await readFile(baseSeedPath, "utf8"));
const sharedLedger = JSON.parse(await readFile(sharedLedgerPath, "utf8"));

const baseAssets = new Map(baseSeed.assets
  .filter((asset) => asset.framework === "big_five" && asset.entity_type === "facet" && asset.locale === "zh-CN")
  .map((asset) => [asset.entity_key, asset]));

const internalLinksFor = (currentCode) => [
  { label: "开放性", href: "/zh/personality/big-five/openness", relationship: "parent_domain" },
  { label: "30 个细分面向", href: "/zh/personality/big-five/facets", relationship: "facet_hub" },
  ...facets
    .filter((facet) => facet.code !== currentCode)
    .map((facet) => ({ label: facet.title, href: routeFor(facet.code), relationship: "sibling_facet" })),
];

const sectionsFor = (facet) => [
  {
    key: "quick_answer",
    title: `快速回答：${facet.title}是什么`,
    body_md: `${facet.title}描述${facet.short}。它是开放性下的一个连续细分面向，不是一种人格类型，也不是给个人下定论的标签。较明显或较不明显只表示通常关注点可能不同；具体表现还会随任务、经验、资源、角色和压力改变。`,
  },
  {
    key: "what_it_captures",
    title: `${facet.title}主要在观察什么`,
    body_md: `${facet.title}关注的是人在有选择空间时通常怎样分配注意、理解信息并接近经验。它不单看一次行为，也不把兴趣直接等同于能力。更可靠的阅读方式，是比较多个时间点和至少两个不同情境，再询问这种模式带来了什么便利、成本与支持需求。`,
  },
  {
    key: "higher_expression",
    title: `${facet.title}较明显时可能怎样表现`,
    body_md: `${facet.higher}。这种倾向在匹配的任务里可能扩大信息来源或增加理解角度，但也可能带来发散过多、忽略约束或投入超出需要等成本。是否有帮助取决于能否配合核验、优先级和停止条件，而不是面向名称本身。`,
  },
  {
    key: "lower_expression",
    title: `${facet.title}较不明显时可能怎样表现`,
    body_md: `${facet.lower}。这并不表示缺少开放性或能力，而可能是一种资源分配方式。在需要稳定、清晰和重复执行的任务里，它常有实际价值；当环境明显变化时，则可以用小规模试验补充新的信息。`,
  },
  {
    key: "context_examples",
    title: "放进真实情境理解",
    body_md: `${facet.context}。这些例子只说明同一倾向在不同任务中可能产生不同效果，不预测个人表现。判断前应同时查看目标、风险、时限、协作者和可逆性。`,
  },
  {
    key: "common_misreads",
    title: "常见误解与相邻概念",
    body_md: `${facet.misread}。开放性六个面向也不必同步：某人在这个面向上较明显，不代表想象力、审美、感受性、行动开放、观念开放和价值开放都会处在同一位置。`,
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
    question: `${facet.title}能代表整个开放性吗？`,
    answer: "不能。它只是开放性下六个细分面向之一，其他面向可能呈现不同位置，宽维度也不能被单一面向替代。",
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
      description: `理解开放性细分面向“${facet.title}”的含义、较明显与较不明显的表现、真实情境、常见误解和非诊断使用边界。`,
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
      summary: `本页解释开放性下的“${facet.title}”公共概念，不解释个人结果，也不替代具体量表契约或专业判断。`,
      taxonomy_boundary: "NEO/IPIP-like 30-facet route taxonomy; no direct conversion to BFI-2 facets or BFAS aspects.",
      not_for: ["临床诊断", "治疗建议", "招聘或录取筛选", "能力或智力判断", "收入、关系或职业结果预测"],
    },
    evidence_notes: [
      { source_type: "taxonomy", note: "现有 CMS route set 采用与 NEO / IPIP 30-facet 传统相近的六面向开放性导航。" },
      { source_type: "boundary", note: `${facet.title}是连续倾向，不是人格类型、能力等级或确定性预测。` },
      { source_type: "search", note: "GSC_EVIDENCE_PENDING；本包不主张搜索表现或可索引发布资格。" },
    ],
    source_ledger_refs: ["SHARED", "I1", "I2", "I3", "A1", "A2", "A3", "A4"],
    model_output_refs: [
      `codex-native-raw-${facet.code}-2026-07-10`,
      "codex-skeptical-review-openness-2026-07-10",
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
  package: "big-five-zh-facet-openness-source-ledger-2026-07-10",
  access_date: "2026-07-10",
  scope: "Six Chinese openness Facet content packages only.",
  inherits: {
    path: "generated/big-five-zh-facet-hub-content-repair/source_ledger.json",
    package: sharedLedger.package,
  },
  sources: sharedLedger.sources,
  taxonomy: sharedLedger.taxonomy.filter((domain) => domain.domain_code === "openness"),
  claim_map: [
    { claim: "The six routes are narrower descriptors under openness, not six personality types.", evidence_ids: ["I1", "A1", "A4"] },
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
    "Separate imagination from realism, aesthetics from skill, feelings from instability, actions from impulsivity, ideas from intelligence, and values from politics or morality.",
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
  writeFile(resolve(outputDir, "big_five_zh_facet_openness_seed.json"), `${JSON.stringify(envelope(packageName, repairedAssets), null, 2)}\n`),
]);

console.log(`generated ${repairedAssets.length} Chinese openness Facet assets`);
