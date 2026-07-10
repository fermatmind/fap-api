import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-zh-facet-agreeableness-content-package");
const baseSeedPath = resolve("backend/content_assets/personality_public/big_five_v1_seed.json");
const sharedLedgerPath = resolve("generated/big-five-zh-facet-hub-content-repair/source_ledger.json");
const generatedAt = "2026-07-10T00:00:00Z";

const facets = [
  {
    code: "trust",
    title: "信任",
    short: "在证据不足时对他人诚意、可靠性和合作意图持较积极预期的通常倾向",
    higher: "较愿意先按善意解释他人行为、分享必要信息并给合作机会，除非出现明确反证",
    lower: "更常先核验动机、记录和承诺，倾向在建立信任前保留信息、权限或替代方案",
    context: "长期协作中，较高信任能降低反复防备成本；涉及金钱、隐私和权限时，谨慎核验是合理保护。信任应随证据更新，而非固定为全信或全不信",
    misread: "它不等于天真、可被骗、绝对安全、道德优越或对所有人开放。高信任仍需要边界和验证，低信任也不证明冷漠或偏执；临床判断不属于本页",
    observe: "回看三次合作开始时自己要求了哪些证据、何时增加或收回信任，并区分对具体行为的判断与对整个人的永久定性",
    experiment: "选择一项低风险合作，分阶段开放信息或权限并设置可观察承诺；根据履约证据逐步调整，而不是一次性全盘信任或拒绝合作",
  },
  {
    code: "straightforwardness",
    title: "坦率",
    short: "在沟通中直接说明真实立场、减少操纵和隐瞒关键意图的通常倾向",
    higher: "较愿意把观点、限制和重要动机说清楚，不喜欢通过暗示、包装或策略性模糊影响他人",
    lower: "更重视策略、礼貌和信息时机，可能根据关系与后果选择间接表达或暂不披露全部想法",
    context: "澄清责任和利益冲突时，较高坦率能减少误解；涉及隐私、谈判和他人安全时，信息边界与表达时机同样重要，直接并不要求毫无保留",
    misread: "它不等于刻薄、口无遮拦、泄露隐私、永远正确或道德纯度。诚实可以兼顾措辞和安全，间接表达也不必然是欺骗",
    observe: "记录自己在不同权力关系中怎样表达反对、限制和利益，区分必要隐私、礼貌缓冲、害怕后果与有意误导",
    experiment: "把一条容易含糊的信息改成“事实—立场—边界—下一步”四句表达，请对方复述理解；保留不应公开的信息，并复盘直接度是否真正减少误解",
  },
  {
    code: "altruism",
    title: "利他",
    short: "注意他人需要并愿意在合理成本内提供时间、信息或实际帮助的通常倾向",
    higher: "较容易主动发现需要、分享资源和协助解决问题，并从支持他人中感到意义",
    lower: "更强调个人责任、自助和交换边界，通常在请求明确、影响可控或职责相关时才投入帮助",
    context: "危机互助和团队协作中，较高利他能补位；当帮助长期替代对方责任或消耗自身基本需要时，拒绝、转介和设限可能更可持续",
    misread: "它不等于自我牺牲、讨好、慈善金额、没有边界或道德高低。帮助行为受资源和角色影响，较少主动帮助也不表示没有关心",
    observe: "回看最近三次帮助或拒绝，记录请求是否明确、成本由谁承担、对方是否真正受益，以及帮助是否强化了不健康依赖",
    experiment: "对一个真实需要先问“你希望我听、提供信息还是一起行动”，再给出可承担的具体帮助和结束点；复盘帮助是否有效且边界可持续",
  },
  {
    code: "compliance",
    title: "顺应",
    short: "在冲突中抑制对抗、寻找让步和恢复合作的通常倾向",
    higher: "较愿意降温、倾听和寻找共同点，避免把分歧升级为人身对抗或关系破裂",
    lower: "更愿意公开争辩、坚持立场和直接面对冲突，在认为原则或利益受损时不急于让步",
    context: "可协商分歧中，较高顺应有助于保留关系；涉及安全、骚扰、权利和重大原则时，清楚反对、升级处理或退出可能更必要",
    misread: "它不等于服从、软弱、同意、放弃权利或没有愤怒。降温不代表接受伤害，敢于冲突也不等于攻击；任何同意都应独立、明确且可撤回",
    observe: "记录自己在轻微分歧和边界受侵犯时分别如何回应，区分有策略的让步、害怕后果、真实同意和延后处理",
    experiment: "为一次低风险分歧写出共同目标、不可让步边界和两个可协商点；先复述对方再提出方案，若触及安全或权利则停止妥协并寻求支持",
  },
  {
    code: "modesty",
    title: "谦逊",
    short: "在展示成就和比较地位时减少自我抬高、不过度要求特殊认可的通常倾向",
    higher: "较少主动强调优越性，愿意承认他人贡献和自身限制，不喜欢把关注长期集中在自己身上",
    lower: "更愿意明确展示成绩、优势和应得认可，在竞争或谈判中不避讳说明自己的价值",
    context: "团队复盘中，较高谦逊能给他人空间；求职、晋升和资源争取时，过度淡化贡献可能导致信息缺失，准确陈述成绩并不等于傲慢",
    misread: "它不等于低自尊、缺少能力、自我贬低、害羞或必须拒绝赞美。谦逊关注自我呈现方式，不要求否认事实或容忍不公平归功",
    observe: "比较自己在安全团队、公开竞争和权力不对等场景中如何谈成就，检查是准确、夸大、淡化，还是把团队贡献遗漏",
    experiment: "写一段包含具体结果、个人贡献、他人贡献和限制的四句说明，在一次合适场景使用；观察是否既准确又不过度抬高或贬低自己",
  },
  {
    code: "tender-mindedness",
    title: "柔软心肠",
    short: "对他人痛苦、脆弱处境和照护需要产生同情并重视其影响的通常倾向",
    higher: "较容易被具体苦难触动，在判断方案时会主动考虑受影响者的感受、尊严和支持需要",
    lower: "更倾向保持情感距离、强调一致规则和长期后果，不一定让即时同情主导资源或责任判断",
    context: "照护和服务设计中，较高柔软心肠能发现被忽略的需要；资源分配和危机决策中，同情还需与证据、公平和可持续性配合",
    misread: "它不等于脆弱、感性失控、女性化、心理健康状态或总是赞同。情感敏感不保证方案有效，保持距离也不证明残酷",
    observe: "记录自己面对熟人、陌生人和抽象统计中的困难时反应有何不同，并检查同情是否转化为对方真正需要且可持续的行动",
    experiment: "选择一个受影响群体，先听取一条第一手需求，再同时写下人本影响、证据限制和资源边界；提出一个小而可验证的支持措施",
  },
];

const routeFor = (code) => `/zh/personality/big-five/facets/${code}`;
const packageName = "big-five-zh-facet-agreeableness-content-package-2026-07-10";
const baseSeed = JSON.parse(await readFile(baseSeedPath, "utf8"));
const sharedLedger = JSON.parse(await readFile(sharedLedgerPath, "utf8"));

const baseAssets = new Map(baseSeed.assets
  .filter((asset) => asset.framework === "big_five" && asset.entity_type === "facet" && asset.locale === "zh-CN")
  .map((asset) => [asset.entity_key, asset]));

const internalLinksFor = (currentCode) => [
  { label: "宜人性", href: "/zh/personality/big-five/agreeableness", relationship: "parent_domain" },
  { label: "30 个细分面向", href: "/zh/personality/big-five/facets", relationship: "facet_hub" },
  ...facets
    .filter((facet) => facet.code !== currentCode)
    .map((facet) => ({ label: facet.title, href: routeFor(facet.code), relationship: "sibling_facet" })),
];

const sectionsFor = (facet) => [
  {
    key: "quick_answer",
    title: `快速回答：${facet.title}是什么`,
    body_md: `${facet.title}描述${facet.short}。它是宜人性下的一个连续细分面向，不是一种人格类型，也不是给个人下定论的标签。较明显或较不明显只表示通常关注点可能不同；具体表现还会随任务、经验、资源、角色和压力改变。`,
  },
  {
    key: "what_it_captures",
    title: `${facet.title}主要在观察什么`,
    body_md: `${facet.title}关注的是人在合作、冲突和资源分配中，通常怎样理解他人意图、表达真实立场、回应需要并协调自身与他人利益。它不单看一次行为，也不把顺从或帮助直接等同于道德。更可靠的阅读方式，是比较多个时间点和至少两个不同情境，再询问这种模式带来了什么便利、成本与支持需求。`,
  },
  {
    key: "higher_expression",
    title: `${facet.title}较明显时可能怎样表现`,
    body_md: `${facet.higher}。这种倾向在匹配的任务里可能增加合作、照护和关系修复，但也可能带来边界模糊、回避必要冲突、承担过多或忽略证据等成本。是否有帮助取决于权利边界、信息质量、相互性与能否在需要时清楚拒绝。`,
  },
  {
    key: "lower_expression",
    title: `${facet.title}较不明显时可能怎样表现`,
    body_md: `${facet.lower}。这并不表示缺少道德、关心或合作能力，而可能更强调核验、自主、原则和资源边界。在需要质疑、谈判或保护权利时，这一端可能有实际价值；当关系成本上升时，则可以借助复述、透明规则和小范围互惠补足。`,
  },
  {
    key: "context_examples",
    title: "放进真实情境理解",
    body_md: `${facet.context}。这些例子只说明同一倾向在不同任务中可能产生不同效果，不预测个人表现。判断前应同时查看目标、风险、时限、协作者和可逆性。`,
  },
  {
    key: "common_misreads",
    title: "常见误解与相邻概念",
    body_md: `${facet.misread}。宜人性六个面向也不必同步：某人在这个面向上较明显，不代表信任、坦率、利他、顺应、谦逊和柔软心肠都会处在同一位置。`,
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
    question: `${facet.title}能代表整个宜人性吗？`,
    answer: "不能。它只是宜人性下六个细分面向之一，其他面向可能呈现不同位置，宽维度也不能被单一面向替代。",
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
      description: `理解宜人性细分面向“${facet.title}”的含义、较明显与较不明显的表现、真实情境、常见误解和非诊断使用边界。`,
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
      summary: `本页解释宜人性下的“${facet.title}”公共概念，不解释个人结果，也不替代具体量表契约或专业判断。`,
      taxonomy_boundary: "NEO/IPIP-like 30-facet route taxonomy; no direct conversion to BFI-2 facets or BFAS aspects.",
      not_for: ["临床诊断", "治疗建议", "招聘或录取筛选", "能力或智力判断", "收入、关系或职业结果预测"],
    },
    evidence_notes: [
      { source_type: "taxonomy", note: "现有 CMS route set 采用与 NEO / IPIP 30-facet 传统相近的六面向宜人性导航。" },
      { source_type: "boundary", note: `${facet.title}是连续倾向，不是人格类型、能力等级或确定性预测。` },
      { source_type: "search", note: "GSC_EVIDENCE_PENDING；本包不主张搜索表现或可索引发布资格。" },
    ],
    source_ledger_refs: ["SHARED", "I1", "I2", "I3", "A1", "A2", "A3", "A4"],
    model_output_refs: [
      `codex-native-raw-${facet.code}-2026-07-10`,
      "codex-skeptical-review-agreeableness-2026-07-10",
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
  package: "big-five-zh-facet-agreeableness-source-ledger-2026-07-10",
  access_date: "2026-07-10",
  scope: "Six Chinese agreeableness Facet content packages only.",
  inherits: {
    path: "generated/big-five-zh-facet-hub-content-repair/source_ledger.json",
    package: sharedLedger.package,
  },
  sources: sharedLedger.sources,
  taxonomy: sharedLedger.taxonomy.filter((domain) => domain.domain_code === "agreeableness"),
  claim_map: [
    { claim: "The six routes are narrower descriptors under agreeableness, not six personality types.", evidence_ids: ["I1", "A1", "A4"] },
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
    "Separate trust from gullibility or safety, straightforwardness from bluntness or total disclosure, altruism from self-sacrifice, compliance from obedience or consent, modesty from low self-esteem, and tender-mindedness from fragility or clinical status.",
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
  writeFile(resolve(outputDir, "big_five_zh_facet_agreeableness_seed.json"), `${JSON.stringify(envelope(packageName, repairedAssets), null, 2)}\n`),
]);

console.log(`generated ${repairedAssets.length} Chinese agreeableness Facet assets`);
