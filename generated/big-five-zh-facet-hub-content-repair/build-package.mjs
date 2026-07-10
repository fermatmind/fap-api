import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-zh-facet-hub-content-repair");
const baseSeedPath = resolve("backend/content_assets/personality_public/big_five_v1_seed.json");
const generatedAt = "2026-07-10T00:00:00Z";

const domainGroups = [
  {
    code: "openness",
    title: "开放性",
    facets: [
      ["imagination", "想象力"],
      ["aesthetics", "审美"],
      ["feelings", "感受性"],
      ["actions", "行动开放"],
      ["ideas", "观念开放"],
      ["values", "价值开放"],
    ],
  },
  {
    code: "conscientiousness",
    title: "尽责性",
    facets: [
      ["competence", "胜任感"],
      ["order", "条理性"],
      ["dutifulness", "责任感"],
      ["achievement-striving", "成就追求"],
      ["self-discipline", "自律"],
      ["deliberation", "审慎"],
    ],
  },
  {
    code: "extraversion",
    title: "外向性",
    facets: [
      ["warmth", "热情"],
      ["gregariousness", "合群性"],
      ["assertiveness", "表达主导性"],
      ["activity", "活跃度"],
      ["excitement-seeking", "刺激寻求"],
      ["positive-emotions", "积极情绪"],
    ],
  },
  {
    code: "agreeableness",
    title: "宜人性",
    facets: [
      ["trust", "信任"],
      ["straightforwardness", "坦率"],
      ["altruism", "利他"],
      ["compliance", "顺应"],
      ["modesty", "谦逊"],
      ["tender-mindedness", "柔软心肠"],
    ],
  },
  {
    code: "neuroticism",
    title: "情绪敏感性（研究中常称神经质）",
    facets: [
      ["anxiety", "焦虑倾向"],
      ["anger", "愤怒倾向"],
      ["depression", "低落倾向"],
      ["self-consciousness", "自我意识"],
      ["impulsiveness", "冲动性"],
      ["vulnerability", "脆弱性"],
    ],
  },
];

const facetHref = (code) => `/zh/personality/big-five/facets/${code}`;
const facetList = (group) => group.facets
  .map(([code, title]) => `- [${title}](${facetHref(code)})`)
  .join("\n");

const sections = [
  {
    key: "quick_answer",
    title: "快速回答：30 个细分面向是什么",
    body_md: "大五人格先用五个宽维度整理较稳定的思考、感受与行为倾向；细分面向（facet）再把每个宽维度拆得更具体。本页采用一套与 NEO / IPIP 30-facet 传统相近的 5×6 导航：五个维度各列六个面向。它们不是 30 种人格，也不是给人贴标签的分类，而是帮助提出更精确观察问题的公共知识框架。",
  },
  {
    key: "facet_domain_relationship",
    title: "细分面向与五个维度是什么关系",
    body_md: "维度像地图上的大区域，细分面向像区域中的不同街区。两个人在同一宽维度上看起来接近，具体表现仍可能不同：一个人可能更愿意探索想法，另一个人更容易被审美体验吸引。阅读时先保留宽维度提供的整体方向，再用细分面向解释内部差异；不要把单个面向当成整个人格的替代品，也不要假设同一维度下六个面向必然同步变化。",
  },
  {
    key: "why_facets",
    title: "为什么要看细分面向",
    body_md: "只看宽维度，容易把不同原因造成的相似表现混在一起。细分面向能把“更愿意探索”“更重视秩序”“更喜欢社交刺激”等问题分开，让反思、沟通和行为实验更具体。它的价值在于增加描述分辨率，而不是提高一个人的等级。任何观察仍会受到情境、角色、技能、资源、压力和时间影响，因此适合被当作待验证的工作假设。",
  },
  {
    key: "openness_facets",
    title: "开放性：六个细分面向",
    body_md: `开放性关注对想象、审美、感受、新行动、观念与价值修正的通常接近方式。六个面向可以不同步：喜欢抽象讨论不等于经常改变生活方式，重视审美体验也不等于偏好所有新事物。阅读这些页面时，应分别观察自己在学习、创作、决策和日常安排中的具体反应。\n\n${facetList(domainGroups[0])}`,
  },
  {
    key: "conscientiousness_facets",
    title: "尽责性：六个细分面向",
    body_md: `尽责性关注如何组织任务、履行承诺、追求目标、维持行动并在决定前权衡。一个人可能很看重责任，却不偏好整齐环境；也可能计划细致，但在长期重复任务上需要额外支持。把六个面向分开，有助于把道德评价改写成可观察的工作方式与支持条件。\n\n${facetList(domainGroups[1])}`,
  },
  {
    key: "extraversion_facets",
    title: "外向性：六个细分面向",
    body_md: `外向性不仅是“爱不爱说话”，还涉及亲近表达、群体偏好、主导表达、活动节奏、刺激偏好与积极情绪体验。有人在一对一交流中很热情，却不喜欢大型聚会；有人行动节奏快，但不一定愿意主导讨论。分开阅读六个面向，可以减少把安静误解为冷淡、把活跃误解为领导力的风险。\n\n${facetList(domainGroups[2])}`,
  },
  {
    key: "agreeableness_facets",
    title: "宜人性：六个细分面向",
    body_md: `宜人性涉及信任、直接表达、帮助倾向、冲突回应、自我呈现与对他人处境的关注。合作不等于没有边界，坦率也不等于缺少关心。一个人可能愿意帮助别人，同时在谈判中坚持立场；也可能较少预设信任，却能遵守清楚的合作规则。六个面向要结合关系、风险与权力情境阅读。\n\n${facetList(domainGroups[3])}`,
  },
  {
    key: "neuroticism_facets",
    title: "情绪敏感性：六个细分面向",
    body_md: `这一维度描述对压力、不确定、威胁和情绪波动的通常敏感程度。页面沿用研究与现有 CMS taxonomy 的面向键，但采用“焦虑倾向”“低落倾向”等非诊断表达。面向名称不能判断任何人是否患有心理疾病，也不能替代专业评估。睡眠、负荷、健康、支持和具体事件都会影响当下反应。\n\n${facetList(domainGroups[4])}`,
  },
  {
    key: "reading_method",
    title: "四步阅读方法",
    body_md: "第一步，先确认正在讨论哪个宽维度；第二步，再找出更贴近问题的一个或两个细分面向；第三步，用至少两个不同情境核对，例如独立工作与团队协作、平稳时期与高压时期；第四步，把结论写成“我目前更常出现的模式可能是……”，并保留反例。若页面与某份量表结果一起使用，应遵循该量表自己的计分、常模和解释规则，本公共页面不代替个人结果解读。",
  },
  {
    key: "cross_facet_examples",
    title: "跨面向组合：为什么不能只看一个标签",
    body_md: "细分面向的意义常出现在组合里。观念开放较明显而审慎也较明显的人，可能先广泛生成方案，再慢慢收敛；热情较明显而合群性较弱的人，可能重视一对一连接，却需要从大型社交中恢复；责任感较明显而条理性较弱的人，可能非常在意承诺，但依赖外部清单维持秩序；焦虑倾向较明显而自律也较明显的人，可能较早觉察风险，同时仍能按计划推进。这些只是解释示例，不是类型、预测或对个人的判定。",
  },
  {
    key: "common_misunderstandings",
    title: "常见误解",
    body_md: "不要把 30 个面向理解为 30 种人格；不要把“高”自动理解为更好，也不要把“低”理解为缺陷；不要因为同属一个维度就假设六个面向完全一致；不要把不同量表中相似名称当作可直接互换的分数；不要用情绪敏感性面向推断诊断、抗压能力或未来表现。面向语言的用途是让问题更具体，最终仍需回到可观察行为、情境与实际反馈。",
  },
  {
    key: "how_to_use",
    title: "把理解转成一个小行动",
    body_md: "选择一个当前真实问题，例如会议参与、任务拖延、冲突沟通或压力恢复。先从五个维度中选出最相关的一个，再从该组六个面向中选出最贴近行为的一个。记录一个具体情境、一个反例和一个可逆的小实验，例如提前写下发言要点、设置开始提示、明确边界句式或预留恢复时间。一周后只复盘行为是否改善，不用面向名称给自己定性。",
  },
  {
    key: "method_boundary",
    title: "方法与证据边界",
    body_md: "人格研究存在多种层级方案。本页使用的 30-facet 导航接近 NEO / IPIP 的六面向结构；BFI-2 使用每个维度三个、合计 15 个 facet，Big Five Aspect Scales 则讨论每个维度两个、合计 10 个 aspect。它们不能被当作同一套分数直接换算。本页不提供信度、效度、常模或百分位数字，也不用于诊断、治疗、招聘筛选、录取、能力判断、收入预测、关系结果预测或确定性职业建议。",
  },
  {
    key: "publish_state",
    title: "这份总览能做什么、不能做什么",
    body_md: "这份总览提供公共概念、导航和反思方法，适合帮助读者把宽泛的人格词汇拆成更清楚的问题。它不读取私人测评结果，不知道个人分数，也不会生成个体结论。真正的个人解释需要结合具体测评契约、作答质量、情境与本人反馈；在高风险决定中，还需要相应领域的专业流程，而不是依赖一个人格页面。",
  },
  {
    key: "related_links",
    title: "下一步：从总览进入具体问题",
    body_md: "如果你刚接触大五人格，可以先返回大五人格总览，理解五个连续维度，再使用大五人格测试作为结构化反思入口。若你已经有一个明确问题，就选择最相关的维度页，然后进入其中一个细分面向；一次只验证一个假设，比同时给自己贴多个标签更容易形成可复盘的行动。",
  },
];

const faq = [
  {
    id: "why-thirty",
    question: "为什么这里是 30 个细分面向？",
    answer: "本页采用与 NEO / IPIP 30-facet 传统相近的五个维度、每个维度六个面向的导航。它是一种常见层级方案，但不是所有大五量表共同且唯一的拆分方式。",
    evidence_ids: ["A1", "A2"],
  },
  {
    id: "are-types",
    question: "30 个细分面向是 30 种人格吗？",
    answer: "不是。细分面向是连续维度内部更窄的描述层，不是离散类型，也不能单独概括一个人。",
    evidence_ids: ["A2", "A3"],
  },
  {
    id: "different-systems",
    question: "为什么别的资料写 10 个或 15 个面向？",
    answer: "不同量表和研究方案使用不同层级：BFI-2 有 15 个 facet，BFAS 讨论 10 个 aspect，本页则采用 30-facet 导航。相似名称不代表分数可以直接换算。",
    evidence_ids: ["A2", "A3"],
  },
  {
    id: "higher-better",
    question: "某个细分面向越高越好吗？",
    answer: "不能这样判断。倾向的作用取决于任务、情境、调节方式和成本；两端都可能带来便利与权衡。",
    evidence_ids: ["I2"],
  },
  {
    id: "read-result",
    question: "这个页面能解释我的个人测评分数吗？",
    answer: "不能。本页只解释公共概念。个人分数应按具体量表的计分、作答质量、常模和解释规则阅读，并结合情境与本人反馈。",
    evidence_ids: ["I1", "I2"],
  },
  {
    id: "high-stakes",
    question: "可以用细分面向做诊断、招聘或职业决定吗？",
    answer: "不可以。细分面向不能替代临床评估、招聘流程、能力证据、职业信息或其他高风险决策所需的专业判断。",
    evidence_ids: ["I2"],
  },
];

const internalLinks = [
  { label: "大五人格总览", href: "/zh/personality/big-five", relationship: "hub" },
  { label: "大五人格测试", href: "/zh/tests/big-five-personality-test-ocean-model", relationship: "test_landing" },
  { label: "开放性", href: "/zh/personality/big-five/openness", relationship: "domain" },
  { label: "尽责性", href: "/zh/personality/big-five/conscientiousness", relationship: "domain" },
  { label: "外向性", href: "/zh/personality/big-five/extraversion", relationship: "domain" },
  { label: "宜人性", href: "/zh/personality/big-five/agreeableness", relationship: "domain" },
  { label: "情绪敏感性", href: "/zh/personality/big-five/neuroticism", relationship: "domain" },
];

const sourceLedger = {
  package: "big-five-zh-facet-shared-source-ledger-2026-07-10",
  access_date: "2026-07-10",
  scope: "Shared source and taxonomy ledger for the Chinese Facet Hub and the thirty later facet-detail packages.",
  sources: [
    {
      id: "I1",
      type: "internal",
      reference: "backend/content_assets/personality_public/big_five_v1_seed.json",
      use: "Frozen CMS field shape, current Chinese route keys, locale, launch state, robots, and 5×6 taxonomy labels.",
      limitation: "The existing short body copy is an input to audit, not an authority for new academic claims.",
    },
    {
      id: "I2",
      type: "internal",
      reference: "fap-web/docs/claims/public-claim-boundary-matrix.md",
      use: "Non-diagnostic, non-deterministic, non-screening public claim boundaries.",
    },
    {
      id: "I3",
      type: "internal",
      reference: "generated/big-five-zh-legacy-route-audit/legacy_route_audit.json",
      use: "Frozen V2 public route family and duplicate-route boundary.",
    },
    {
      id: "A1",
      type: "official_research_resource",
      title: "NEO Facets Table",
      author_or_source: "International Personality Item Pool, Oregon Research Institute",
      year: null,
      url: "https://ipip.ori.org/newNEO_FacetsTable.htm",
      accessed_at: "2026-07-10",
      claim: "Documents corresponding IPIP scales for the 30 NEO-PI-R facet constructs.",
      limitation: "IPIP scales measure similar constructs; this does not make them identical to proprietary NEO scales or authorize score conversion.",
    },
    {
      id: "A2",
      type: "peer_reviewed",
      title: "The next Big Five Inventory (BFI-2): Developing and assessing a hierarchical model with 15 facets",
      author_or_source: "Soto, C. J., & John, O. P.",
      year: 2017,
      doi: "10.1037/pspp0000096",
      url: "https://pubmed.ncbi.nlm.nih.gov/27055049/",
      accessed_at: "2026-07-10",
      claim: "Supports a hierarchical domain-and-facet reading of Big Five traits using 15 facets.",
      limitation: "BFI-2 has 15 facets, not the 30-facet taxonomy used for this CMS route set.",
    },
    {
      id: "A3",
      type: "peer_reviewed",
      title: "Between facets and domains: 10 aspects of the Big Five",
      author_or_source: "DeYoung, C. G., Quilty, L. C., & Peterson, J. B.",
      year: 2007,
      doi: "10.1037/0022-3514.93.5.880",
      url: "https://pubmed.ncbi.nlm.nih.gov/17983306/",
      accessed_at: "2026-07-10",
      claim: "Shows an alternative intermediate hierarchy with two aspects per domain.",
      limitation: "The ten aspects are not interchangeable with either BFI-2 facets or NEO/IPIP 30 facets.",
    },
    {
      id: "A4",
      type: "academic_review",
      title: "Paradigm Shift to the Integrative Big Five Trait Taxonomy: History, Measurement, and Conceptual Issues",
      author_or_source: "John, O. P., Naumann, L. P., & Soto, C. J.",
      year: 2008,
      reference: "In Handbook of Personality: Theory and Research (3rd ed.), pp. 114–158.",
      claim: "Supports broad Big Five taxonomy framing and continuous trait language.",
      limitation: "No individual prediction, numeric validity, or universal facet naming claim is taken from this review.",
    },
  ],
  taxonomy: domainGroups.map((group) => ({
    domain_code: group.code,
    domain_title_zh: group.title,
    facets: group.facets.map(([code, title]) => ({
      code,
      title_zh: title,
      route: facetHref(code),
    })),
  })),
  claim_map: [
    { claim: "Five broad domains can be read hierarchically with narrower traits.", evidence_ids: ["A2", "A3", "A4"] },
    { claim: "This route set uses six facets per domain and thirty total.", evidence_ids: ["I1", "A1"] },
    { claim: "Facet systems differ and must not be treated as directly interchangeable.", evidence_ids: ["A1", "A2", "A3"] },
    { claim: "Public pages must remain non-diagnostic and unsuitable for high-stakes decisions.", evidence_ids: ["I2"] },
  ],
  limitations: [
    "The frozen CMS taxonomy is NEO/IPIP-like; it is not claimed as the single universal Big Five facet standard.",
    "No proprietary assessment items, scoring keys, norms, or copyrighted manual text are reproduced.",
    "No reliability, validity, percentile, clinical, hiring, admission, salary, relationship-outcome, or deterministic career claim is made.",
    "GSC_EVIDENCE_PENDING: this package is content-authority preparation, not a search-performance claim.",
  ],
};

const baseSeed = JSON.parse(await readFile(baseSeedPath, "utf8"));
const baseHub = baseSeed.assets.find((asset) => (
  asset.framework === "big_five"
  && asset.entity_type === "facet_hub"
  && asset.entity_key === "facets"
  && asset.locale === "zh-CN"
));

if (!baseHub) {
  throw new Error("Missing zh-CN Big Five facet_hub base asset.");
}

const rawAsset = structuredClone(baseHub);
rawAsset.sections = rawAsset.sections.map((section) => {
  const { body, ...rest } = section;
  return { ...rest, body_md: body ?? "" };
});
rawAsset.review_state = "codex_raw_untrusted";
rawAsset.source_package = "big-five-zh-facet-hub-raw-codex-draft-2026-07-10";
rawAsset.source_hash = null;
rawAsset.last_reviewed_at = generatedAt;
rawAsset.source_ledger_refs = ["I1", "I2", "A1", "A2", "A3", "A4"];
rawAsset.model_output_refs = ["codex-native-raw-2026-07-10"];

const repairedAsset = structuredClone(baseHub);
repairedAsset.summary = "大五人格 30 个细分面向把五个宽维度拆成更具体的观察问题。本页提供 5×6 导航、跨面向阅读方法与证据边界，不把面向当成人格类型或个人结论。";
repairedAsset.seo = {
  title: "大五人格 30 个细分面向：5×6 导航与阅读方法",
  description: "理解大五人格五个维度下的 30 个细分面向，查看 5×6 导航、跨面向示例、常见误解与非诊断使用边界。",
};
repairedAsset.sections = sections;
repairedAsset.faq = faq;
repairedAsset.internal_links = internalLinks;
repairedAsset.robots = "noindex,follow";
repairedAsset.index_eligible = false;
repairedAsset.sitemap_eligible = false;
repairedAsset.llms_eligible = false;
repairedAsset.launch_state = "content_ready";
repairedAsset.review_state = "codex_repaired_ready";
repairedAsset.source_package = "big-five-zh-facet-hub-content-repair-2026-07-10";
repairedAsset.source_hash = null;
repairedAsset.last_reviewed_at = generatedAt;
repairedAsset.evidence_notes = [
  "本页采用与 NEO / IPIP 30-facet 传统相近、并由现有 CMS 路由冻结的 5×6 taxonomy；不同大五量表的层级和命名并不相同。",
  "BFI-2 的 15 facets 与 BFAS 的 10 aspects 只用于说明存在替代层级，不与本页 30 个面向直接换算。",
  "本页是公共解释内容，不读取私人结果，不用于诊断、治疗、招聘筛选、录取、能力判断或确定性职业与关系结论。",
];
repairedAsset.method_boundary = {
  summary: "本页解释一般性大五人格层级语言，不解释个人测评，不替代具体量表契约或专业判断。",
  taxonomy_boundary: "NEO/IPIP-like 30-facet navigation; not a universal or cross-instrument score-conversion standard.",
  not_for: ["临床诊断", "治疗建议", "招聘或录取筛选", "能力或智力判断", "收入、关系或职业结果预测"],
};
repairedAsset.schema = {
  ...repairedAsset.schema,
  status: "noindex_content_repair_01",
};
repairedAsset.source_ledger_refs = ["I1", "I2", "I3", "A1", "A2", "A3", "A4"];
repairedAsset.model_output_refs = [
  "codex-native-raw-2026-07-10",
  "codex-skeptical-review-2026-07-10",
  "codex-repair-2026-07-10",
];

const envelope = (name, asset) => ({
  package: name,
  contract_version: "personality_public_asset.v1",
  generated_at: generatedAt,
  assets: [asset],
});

const skepticalReview = {
  package: "big-five-zh-facet-hub-content-repair-2026-07-10",
  raw_draft: "raw_codex_draft.json",
  reviewer_mode: "codex_skeptical_self_review",
  critical_violations: [],
  major_repairs: [
    "Replace short placeholder sentences with substantive Chinese explanations and a complete 5×6 navigation.",
    "Correct the raw FAQ implication that BFI-2 supports this exact 30-facet taxonomy; BFI-2 uses 15 facets.",
    "Remove public-facing implementation language about noindex, sitemap, llms, and future route rollout from the editorial body.",
    "Add a cross-instrument taxonomy boundary for NEO/IPIP 30 facets, BFI-2 15 facets, and BFAS 10 aspects.",
    "Add cross-facet examples, common misunderstandings, an actionable reading method, and six non-diagnostic FAQs.",
  ],
  minor_repairs: [
    "Use Chinese-first wording for facet while preserving the English term once for precision.",
    "Use body_md exclusively and retain a strict V1 assets envelope.",
    "Replace the self-link in structured internal links with the fifth domain page while keeping seven unique links.",
  ],
  private_result_boundary: "pass_after_repair",
  duplicate_template_risk: "pass_for_single_hub_after_repair",
  adjudication: "repaired_required",
};

const rawEnvelope = envelope("big-five-zh-facet-hub-raw-codex-draft-2026-07-10", rawAsset);
const repairedEnvelope = envelope("big-five-zh-facet-hub-repaired-codex-draft-2026-07-10", repairedAsset);
const seedEnvelope = envelope("big-five-zh-facet-hub-content-repair-2026-07-10", repairedAsset);

await mkdir(outputDir, { recursive: true });
await Promise.all([
  writeFile(resolve(outputDir, "source_ledger.json"), `${JSON.stringify(sourceLedger, null, 2)}\n`),
  writeFile(resolve(outputDir, "raw_codex_draft.json"), `${JSON.stringify(rawEnvelope, null, 2)}\n`),
  writeFile(resolve(outputDir, "skeptical_review.json"), `${JSON.stringify(skepticalReview, null, 2)}\n`),
  writeFile(resolve(outputDir, "repaired_draft.json"), `${JSON.stringify(repairedEnvelope, null, 2)}\n`),
  writeFile(resolve(outputDir, "big_five_zh_facet_hub_seed.json"), `${JSON.stringify(seedEnvelope, null, 2)}\n`),
]);

console.log("generated Big Five zh Facet Hub content package");
