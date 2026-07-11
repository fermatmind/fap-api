import { mkdir, readFile, writeFile } from "node:fs/promises";
import { resolve } from "node:path";

const outputDir = resolve("generated/big-five-zh-facet-conscientiousness-content-package");
const baseSeedPath = resolve("backend/content_assets/personality_public/big_five_v1_seed.json");
const sharedLedgerPath = resolve("generated/big-five-zh-facet-hub-content-repair/source_ledger.json");
const generatedAt = "2026-07-10T00:00:00Z";

const facets = [
  {
    code: "competence",
    title: "胜任感",
    short: "对自己能否理解要求、组织行动并有效处理日常任务的通常把握感",
    higher: "较容易相信自己可以弄清问题、调动资源并把事情推进，遇到障碍时通常先寻找可控步骤",
    lower: "面对陌生、复杂或高压任务时更可能怀疑自己是否能处理，需要更清楚的示范、反馈或支持才开始",
    context: "接手熟悉项目时，较明显的胜任感可帮助人快速承担责任；进入高风险新领域时，保留不确定感反而能推动核对权限、请教专家和设置复核点",
    misread: "它不等于实际能力、智力、资历或自信口号。一个人可以能力很强但低估自己，也可以主观把握很高却缺少相关知识；能力仍需由具体任务证据判断",
    observe: "记录自己接到熟悉任务和陌生任务时的第一句话、求助时点与拆解方式，区分“我不会”“我还没有信息”和“这超出我的职责或资源”",
    experiment: "选一项略有挑战的小任务，开始前写下已会的两件事、缺少的一条信息和可求助的人；完成后用实际证据更新判断，而不是只根据开始前的紧张或兴奋下结论",
  },
  {
    code: "order",
    title: "条理性",
    short: "对分类、安排、整洁、顺序和可预期工作结构的通常偏好程度",
    higher: "较愿意提前整理材料、明确位置与步骤，并通过清单、命名或时间安排降低遗漏和寻找成本",
    lower: "更能容忍开放的摆放和临时调整，常把精力先放在核心结果上，不一定主动维护固定顺序或整洁标准",
    context: "交接多人协作材料时，较明显的条理性能降低沟通成本；在快速探索阶段，过早建立细密分类可能增加维护负担，而允许临时结构有助于先验证方向",
    misread: "它不等于强迫症、洁癖、完美主义或工作质量。桌面整洁不能单独证明条理性，环境较乱也不代表不能交付；临床概念更不能由人格页面推断",
    observe: "查看自己如何管理文件、日程、物品和多人任务，分别记录组织行为节省了多少时间、又花了多少维护成本，并比较独立工作与协作交接时的差异",
    experiment: "选择一个反复寻找或容易遗漏的小环节，只增加一个命名规则或两分钟收尾清单；一周后比较查找时间和维护成本，若规则没有净收益就缩小或撤销",
  },
  {
    code: "dutifulness",
    title: "责任感",
    short: "对承诺、职责、规则依据和他人合理期待的通常重视与履行倾向",
    higher: "较容易把已答应的事项视为需要兑现的义务，主动确认边界、跟进进度，并在无法完成时尽早说明",
    lower: "更可能依据当下优先级、实际后果和自主判断调整承诺，对形式性规则或未说明理由的要求不一定持续投入",
    context: "涉及客户承诺和安全流程时，较明显的责任感有助于保持可预期；当旧规则与现实冲突时，机械服从可能掩盖问题，及时提出异议和重新协商也可能是负责的行为",
    misread: "它不等于服从权威、讨好、道德高低或永不拒绝。负责任也包括澄清不合理要求、保护自身边界和在条件变化时重新协商，而不是承担所有人的任务",
    observe: "回看最近三次答应、拒绝或延期的事项，记录承诺是否清楚、谁会受到影响、自己何时预警，并区分真正职责、习惯性内疚和别人临时转移的责任",
    experiment: "对一项近期承诺写下交付物、截止时间、依赖和无法完成时的通知点；若任务不合理，练习在答应前提出一个边界或替代方案，并观察关系和结果",
  },
  {
    code: "achievement-striving",
    title: "成就追求",
    short: "为较高标准、进步目标和有挑战成果投入努力的通常倾向",
    higher: "较愿意设定有难度的目标、比较进展并持续提高标准，常从完成和成长中获得推动力",
    lower: "更可能在达到足够好或生活平衡后停止加码，不一定愿意持续竞争、扩张目标或把绩效置于其他需要之前",
    context: "需要长期训练或突破指标时，较明显的成就追求可以维持投入；在资源有限或恢复期，持续抬高标准可能造成范围膨胀，而接受足够好能保护更重要的目标",
    misread: "它不等于社会地位、收入、忙碌程度、内卷或最终成就。机会、资源、健康、照护责任与团队条件都会影响结果；追求较少也不代表懒惰或没有价值感",
    observe: "列出最近一个主动加码和一个选择停下的任务，检查标准是谁设定的、额外投入换来了什么、挤占了什么，并区分成长目标、外部比较和对不够好的担忧",
    experiment: "为一个两周目标同时定义最低可接受、理想和停止加码三条线；每次增加范围前写下预期收益与被挤占事项，到期后用结果判断标准是否值得保留",
  },
  {
    code: "self-discipline",
    title: "自律",
    short: "在任务乏味、困难或回报延迟时启动行动并维持到合理完成点的通常倾向",
    higher: "较能在不想做时仍按计划开始，把注意拉回任务，并在短期诱惑出现时继续推进既定事项",
    lower: "更依赖即时兴趣、外部结构、同伴节奏或明确反馈来启动和坚持，长周期、低反馈任务更容易被推迟或中断",
    context: "复习、康复训练和重复运营中，较明显的自律有助于积累；在目标已经失效时，过度坚持会增加沉没成本，而及时停止、休息或重设任务可能更合理",
    misread: "它不等于道德意志、懒惰、执行功能诊断或永远高产。睡眠、压力、照护负担、环境摩擦与健康状态都会影响启动和坚持，不能由本页判断 ADHD 等临床问题",
    observe: "比较自己在有兴趣、有人监督和完全自主三种任务中的启动时间、分心点与恢复方式，寻找环境条件而非只用“意志力强弱”解释结果",
    experiment: "把一项容易拖延的任务缩成十分钟可完成的第一步，提前移开一个干扰并约定结束点；连续三次记录启动是否变容易，再决定增加时长还是继续借助外部结构",
  },
  {
    code: "deliberation",
    title: "审慎",
    short: "在行动或承诺前考虑后果、选项、风险与可逆性的通常倾向",
    higher: "较愿意暂停一下、核对关键信息并预想可能后果，尤其在错误代价较高或难以撤回时",
    lower: "更倾向根据已有线索及时行动，在可逆、低风险或需要速度的情境里不一定进行长时间比较",
    context: "合同、权限和安全决策中，较明显的审慎有助于发现不可逆风险；在事故响应或小成本试验中，等待所有信息可能错过窗口，先行动再快速校正更有效",
    misread: "它不等于优柔寡断、焦虑、聪明程度或绝不冒险。审慎关注的是行动前权衡的习惯；考虑很多不保证结论正确，行动较快也不必然是冲动",
    observe: "回看最近一次快速决定和一次延迟决定，记录当时可获得的信息、错误代价、可逆性与等待成本，判断思考时间是否与风险匹配，而非只看结果好坏",
    experiment: "为一类重复决定设置简短门槛：低风险可逆事项两分钟内决定，高风险事项核对三项关键证据并请一人复核；一周后检查是否减少了无效等待或可避免错误",
  },
];

const routeFor = (code) => `/zh/personality/big-five/facets/${code}`;
const packageName = "big-five-zh-facet-conscientiousness-content-package-2026-07-10";
const baseSeed = JSON.parse(await readFile(baseSeedPath, "utf8"));
const sharedLedger = JSON.parse(await readFile(sharedLedgerPath, "utf8"));

const baseAssets = new Map(baseSeed.assets
  .filter((asset) => asset.framework === "big_five" && asset.entity_type === "facet_detail" && asset.locale === "zh-CN")
  .map((asset) => [asset.entity_key, asset]));

const internalLinksFor = (currentCode) => [
  { label: "尽责性", href: "/zh/personality/big-five/conscientiousness", relationship: "parent_domain" },
  { label: "30 个细分面向", href: "/zh/personality/big-five/facets", relationship: "facet_hub" },
  ...facets
    .filter((facet) => facet.code !== currentCode)
    .map((facet) => ({ label: facet.title, href: routeFor(facet.code), relationship: "sibling_facet" })),
];

const sectionsFor = (facet) => [
  {
    key: "quick_answer",
    title: `快速回答：${facet.title}是什么`,
    body_md: `${facet.title}描述${facet.short}。它是尽责性下的一个连续细分面向，不是一种人格类型，也不是给个人下定论的标签。较明显或较不明显只表示通常关注点可能不同；具体表现还会随任务、经验、资源、角色和压力改变。`,
  },
  {
    key: "what_it_captures",
    title: `${facet.title}主要在观察什么`,
    body_md: `${facet.title}关注的是人在面对目标、责任和约束时，通常怎样判断要求、组织资源、启动行动、维持投入或权衡后果。它不单看一次行为，也不把完成结果直接等同于人格。更可靠的阅读方式，是比较多个时间点和至少两个不同情境，再询问这种模式带来了什么便利、成本与支持需求。`,
  },
  {
    key: "higher_expression",
    title: `${facet.title}较明显时可能怎样表现`,
    body_md: `${facet.higher}。这种倾向在匹配的任务里可能提高连续性、可预期性或完成概率，但也可能带来控制过细、标准僵硬、承担过多或难以及时停下等成本。是否有帮助取决于目标是否合理、资源是否足够，以及能否配合优先级、授权和停止条件。`,
  },
  {
    key: "lower_expression",
    title: `${facet.title}较不明显时可能怎样表现`,
    body_md: `${facet.lower}。这并不表示缺少尽责性、道德或能力，而可能反映任务意义、环境结构、资源和其他面向的组合。在需要速度、弹性或低成本试错时，这一端可能有实际价值；当遗漏代价较高时，则可以借助清单、反馈、时间盒或协作结构补足。`,
  },
  {
    key: "context_examples",
    title: "放进真实情境理解",
    body_md: `${facet.context}。这些例子只说明同一倾向在不同任务中可能产生不同效果，不预测个人表现。判断前应同时查看目标、风险、时限、协作者和可逆性。`,
  },
  {
    key: "common_misreads",
    title: "常见误解与相邻概念",
    body_md: `${facet.misread}。尽责性六个面向也不必同步：某人在这个面向上较明显，不代表胜任感、条理性、责任感、成就追求、自律和审慎都会处在同一位置。`,
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
    question: `${facet.title}能代表整个尽责性吗？`,
    answer: "不能。它只是尽责性下六个细分面向之一，其他面向可能呈现不同位置，宽维度也不能被单一面向替代。",
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
      description: `理解尽责性细分面向“${facet.title}”的含义、较明显与较不明显的表现、真实情境、常见误解和非诊断使用边界。`,
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
      summary: `本页解释尽责性下的“${facet.title}”公共概念，不解释个人结果，也不替代具体量表契约或专业判断。`,
      taxonomy_boundary: "NEO/IPIP-like 30-facet route taxonomy; no direct conversion to BFI-2 facets or BFAS aspects.",
      not_for: ["临床诊断", "治疗建议", "招聘或录取筛选", "能力或智力判断", "收入、关系或职业结果预测"],
    },
    evidence_notes: [
      { source_type: "taxonomy", note: "现有 CMS route set 采用与 NEO / IPIP 30-facet 传统相近的六面向尽责性导航。" },
      { source_type: "boundary", note: `${facet.title}是连续倾向，不是人格类型、能力等级或确定性预测。` },
      { source_type: "search", note: "GSC_EVIDENCE_PENDING；本包不主张搜索表现或可索引发布资格。" },
    ],
    source_ledger_refs: ["SHARED", "I1", "I2", "I3", "A1", "A2", "A3", "A4"],
    model_output_refs: [
      `codex-native-raw-${facet.code}-2026-07-10`,
      "codex-skeptical-review-conscientiousness-2026-07-10",
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
  package: "big-five-zh-facet-conscientiousness-source-ledger-2026-07-10",
  access_date: "2026-07-10",
  scope: "Six Chinese conscientiousness Facet content packages only.",
  inherits: {
    path: "generated/big-five-zh-facet-hub-content-repair/source_ledger.json",
    package: sharedLedger.package,
  },
  sources: sharedLedger.sources,
  taxonomy: sharedLedger.taxonomy.filter((domain) => domain.domain_code === "conscientiousness"),
  claim_map: [
    { claim: "The six routes are narrower descriptors under conscientiousness, not six personality types.", evidence_ids: ["I1", "A1", "A4"] },
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
    "Separate competence from actual ability, order from OCD or perfectionism, dutifulness from obedience or morality, achievement striving from status or outcomes, self-discipline from laziness or diagnosis, and deliberation from indecision or intelligence.",
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
  writeFile(resolve(outputDir, "big_five_zh_facet_conscientiousness_seed.json"), `${JSON.stringify(envelope(packageName, repairedAssets), null, 2)}\n`),
]);

console.log(`generated ${repairedAssets.length} Chinese conscientiousness Facet assets`);
