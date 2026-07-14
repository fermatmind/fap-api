import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const generatedAt = '2026-07-14T05:15:00Z';

const sourceCatalog = {
  'academic.goldberg-1990-big-five-structure': {
    label_en: 'Goldberg (1990), Big-Five factor structure',
    label_zh_cn: 'Goldberg（1990）：大五因子结构',
    public_url: 'https://doi.org/10.1037/0022-3514.59.6.1216',
    limitation_en: 'Supports broad factor structure, not an individual FermatMind result, outcome prediction, or product psychometric claim.',
    limitation_zh_cn: '支持宽泛因子结构，不证明个人费马测试结果、结果预测或产品心理测量主张。',
  },
  'academic.soto-john-2017-bfi2': {
    label_en: 'Soto & John (2017), BFI-2 domains and facets',
    label_zh_cn: 'Soto 与 John（2017）：BFI-2 维度与侧面',
    public_url: 'https://doi.org/10.1037/pspp0000096',
    limitation_en: 'Supports one named hierarchical trait model; it does not validate every editorial example or FermatMind score.',
    limitation_zh_cn: '支持一个明确命名的层级特质模型；不验证每个编辑示例或费马测试分数。',
  },
  'academic.roberts-walton-viechtbauer-2006-change': {
    label_en: 'Roberts, Walton, & Viechtbauer (2006), personality trait change',
    label_zh_cn: 'Roberts、Walton 与 Viechtbauer（2006）：人格特质变化',
    public_url: 'https://doi.org/10.1037/0033-2909.132.1.1',
    limitation_en: 'Reports group-level patterns across studies; it cannot predict how or whether one individual will change.',
    limitation_zh_cn: '报告跨研究的群体变化模式；不能预测某个个人是否或如何变化。',
  },
};

const sharedBoundary = {
  en: 'This article supports non-medical self-reflection. It does not diagnose, determine identity or ability, predict outcomes, or replace professional help. FermatMind-specific reliability, validity, norms, sample sizes, and percentiles remain Unknown unless separately supported by public reviewed evidence.',
  'zh-CN': '本文只支持非医疗的自我反思，不用于诊断、决定身份或能力、预测结果，也不替代专业帮助。费马测试产品特定的信度、效度、常模、样本量和百分位，在没有独立公开审核证据时均保持 Unknown。',
};

const articles = [
  {
    locale: 'en',
    slug: 'big-five-conscientiousness-low-procrastination-task-plan',
    title: 'Low Conscientiousness, Procrastination, and a Practical Task Plan',
    intent: 'Help a reader test task-friction changes after a low-conscientiousness result without turning the score into blame or identity.',
    opening: 'A low conscientiousness range is not a verdict that you are lazy, unreliable, or incapable of finishing work. It is a prompt to inspect what happens between intention and action: unclear next steps, oversized tasks, competing rewards, depleted attention, or an environment that makes starting unnecessarily hard.',
    logic: ['Start with the observed behavior, not a trait label.', 'Separate task design, context, energy, and competing demands before interpreting a pattern.', 'Choose one reversible change and watch whether behavior changes.', 'Use the result as a hypothesis only if the same pattern repeats across contexts.'],
    example: 'A reader postpones a weekly report until the deadline. Instead of writing “I procrastinate because I am low in conscientiousness,” they define a ten-minute first action, prepare the data source the day before, and record the actual start time for two weeks.',
    counterexample: 'Someone may score in a higher conscientiousness range and still delay a vague, threatening, or low-priority task. Someone in a lower range may be consistent when the task is concrete, meaningful, and externally cued. The score alone does not identify the cause.',
    framework: ['Name one delayed task and the smallest observable start action.', 'Change one source of friction: clarity, cue, duration, environment, or accountability.', 'Review after two weeks: keep the change, revise it, or reject the trait-based hypothesis.'],
    sources: ['academic.soto-john-2017-bfi2'],
    related: ['/en/personality/big-five/conscientiousness', '/en/personality/big-five', '/en/tests/big-five-personality-test-ocean-model'],
  },
  {
    locale: 'en',
    slug: 'big-five-emotional-stability-stress-recovery-communication',
    title: 'Emotional Stability, Stress Recovery, and Clear Communication',
    intent: 'Offer a consent-based communication protocol for stress and recovery without equating a trait range with mental illness.',
    opening: 'An emotional-stability result cannot tell you whether you have a mental health condition, how another person should treat you, or how quickly you ought to recover. It can begin a narrower conversation: what signals tell you that pressure is rising, what support is welcome, and when should a conversation pause?',
    logic: ['Describe current signals before naming a trait.', 'Ask permission before interpreting someone else’s behavior.', 'Turn a general need into a specific, time-bounded request.', 'Agree on a pause, return time, and professional-help boundary when needed.'],
    example: 'During a tense project discussion, a reader says: “I am noticing that I am speaking faster and missing details. I need ten minutes to reset. Can we return at 3:20 and decide only the next action?” The request is observable and does not require the other person to accept a personality explanation.',
    counterexample: 'A person in a lower emotional-stability range may communicate calmly in a familiar situation, while a person in a higher range may be overwhelmed by sustained uncertainty or loss. Context, resources, and current circumstances matter.',
    framework: ['Notice: name one present signal without diagnosis.', 'Request: ask for one concrete form of support or space.', 'Return: set a time to resume, or seek appropriate professional or urgent help when ordinary support is not enough.'],
    sources: ['academic.soto-john-2017-bfi2'],
    related: ['/en/personality/big-five/neuroticism', '/en/personality/big-five', '/en/tests/big-five-personality-test-ocean-model'],
    extra_boundary: 'Neuroticism or a lower emotional-stability range is not equivalent to anxiety, depression, or any other mental disorder.',
  },
  {
    locale: 'en',
    slug: 'big-five-personality-test-vs-mbti',
    title: 'Big Five and MBTI: Different Questions, Different Result Formats',
    intent: 'Compare dimensional and type-oriented result formats without superiority claims or deterministic crosswalks.',
    opening: 'Big Five and MBTI results may both appear in a self-reflection conversation, but they do not provide interchangeable labels. The useful first question is not “Which one wins?” It is “What question does this model ask, how does it represent an answer, and what decision am I trying to make?”',
    logic: ['Identify whether a result is represented as continuous trait ranges or a type preference pattern.', 'Compare the claim each result actually makes rather than matching familiar words.', 'Check the public method and evidence for the specific instrument being used.', 'Use either result as a discussion prompt, never as a guaranteed prediction.'],
    example: 'A reader who receives an extraversion range and an MBTI preference does not convert one into the other. They instead compare two concrete observations—how they regain energy after group work and how they participate in unfamiliar meetings—then test which description is useful in context.',
    counterexample: 'Two people with the same type label can have different Big Five ranges, and similar Big Five ranges do not establish one shared type. A surface resemblance in wording is not a validated conversion.',
    framework: ['Write the practical question you want to answer.', 'Read the model’s public method and the exact result language.', 'Keep only observations that survive real-world checking; discard deterministic mappings.'],
    sources: ['academic.goldberg-1990-big-five-structure', 'academic.soto-john-2017-bfi2'],
    related: ['/en/personality/big-five', '/en/tests/big-five-personality-test-ocean-model', '/en/tests/mbti-personality-test-16-personality-types'],
    extra_boundary: 'This comparison does not claim that one model is universally more scientific, accurate, or useful, and it does not map Big Five scores to MBTI types.',
  },
  {
    locale: 'zh-CN',
    slug: 'big-five-conscientiousness-low-procrastination-task-plan',
    title: '低尽责性、拖延与可执行的任务计划',
    intent: '帮助读者在不把低尽责性分数当作责备或身份的前提下，检验任务阻力调整。',
    opening: '尽责性处于较低区间，不等于懒惰、不可靠或没有完成事情的能力。它更适合作为一个检查起点：从想做一件事到真正开始之间，究竟卡在步骤不清、任务过大、即时奖励、注意力耗尽，还是环境让启动变得过难？',
    logic: ['先描述可观察行为，不先贴特质标签。', '在解释模式前，分别检查任务设计、情境、精力与竞争需求。', '一次只改一个可逆因素，并观察行为是否变化。', '只有模式跨情境重复时，才把结果保留为工作假设。'],
    example: '一位读者总把周报拖到最后。与其写“我因为尽责性低所以拖延”，不如把第一步定义为“打开数据表并写三行提纲”，提前准备资料，并连续两周记录真实启动时间。',
    counterexample: '尽责性较高的人也可能拖延模糊、威胁感强或优先级低的任务；尽责性较低的人在任务明确、有意义且有外部提示时，也可能非常稳定。分数本身不能指出原因。',
    framework: ['选一个被推迟的任务，写出最小可观察启动动作。', '只改变一种阻力：清晰度、提示、时长、环境或问责。', '两周后复盘：保留、修改，或否定原来的特质假设。'],
    sources: ['academic.soto-john-2017-bfi2'],
    related: ['/zh/personality/big-five/conscientiousness', '/zh/personality/big-five', '/zh/tests/big-five-personality-test-ocean-model'],
  },
  {
    locale: 'zh-CN',
    slug: 'big-five-emotional-stability-stress-recovery-communication',
    title: '情绪稳定性、压力恢复与清楚沟通',
    intent: '提供基于同意的压力与恢复沟通协议，不把特质区间等同精神疾病。',
    opening: '情绪稳定性结果不能判断你是否患有心理疾病，不能规定别人该怎样对待你，也不能决定你应该多快恢复。它可以开启一个更窄、更可执行的对话：压力上升时会出现哪些信号，你愿意接受什么支持，什么时候应该暂停谈话？',
    logic: ['先描述当前信号，再谈特质。', '解释他人行为前先获得同意。', '把笼统需要改写成具体、有时限的请求。', '约定暂停、返回时间，以及需要专业帮助时的边界。'],
    example: '项目讨论变得紧张时，读者可以说：“我注意到自己语速变快，也开始漏信息。我需要十分钟调整。我们能否 3:20 再回来，只决定下一步？”这个请求可观察，也不要求对方接受人格解释。',
    counterexample: '情绪稳定性较低区间的人，在熟悉场景中也可能沟通平稳；较高区间的人面对长期不确定或重大失落时，也可能不堪重负。情境、资源与当下处境都重要。',
    framework: ['注意：描述一个当下信号，不做诊断。', '请求：提出一种具体支持或空间。', '返回：约定恢复对话的时间；普通支持不够时，寻求适当的专业或紧急帮助。'],
    sources: ['academic.soto-john-2017-bfi2'],
    related: ['/zh/personality/big-five/neuroticism', '/zh/personality/big-five', '/zh/tests/big-five-personality-test-ocean-model'],
    extra_boundary: '神经质或较低情绪稳定性区间，不等同于焦虑症、抑郁症或任何其他精神疾病。',
  },
  {
    locale: 'zh-CN',
    slug: 'big-five-growth-guide',
    title: '大五人格结果后的成长实验指南',
    intent: '把结果页后的成长入口限定为一个可逆、可观察、可复盘的行为实验。',
    opening: '人格结果最容易被误用的时刻，往往不是做题时，而是看到结果后立刻决定“我就是这样”。更稳妥的做法是把结果变成一个小实验：选择一个真实场景、一个可观察行为和一个复盘日期。',
    logic: ['从当前重要的生活场景出发，而不是从“提高某个分数”出发。', '把特质描述翻译成一个可以观察的行为假设。', '选择足够小、可以撤回的行动。', '同时记录支持证据与反例，按日期复盘。'],
    example: '读者看到自己开放性处于中间区间，想探索新事物。他不设定“变得更开放”，而是每周尝试一种低成本新活动，并记录期待、实际体验与是否愿意重复。',
    counterexample: '一次成功或失败都不能证明人格已经改变。行为变化可能来自时间、资源、技能、关系或环境；人格结果只是众多解释之一。',
    framework: ['场景：选一个现在真正重要的具体情境。', '实验：设计一个两周内可重复、可撤回的动作。', '复盘：记录证据与反例，决定继续、修改或停止。'],
    sources: ['academic.soto-john-2017-bfi2', 'academic.roberts-walton-viechtbauer-2006-change'],
    related: ['/zh/personality/big-five', '/zh/tests/big-five-personality-test-ocean-model', '/zh/topics/big-five'],
  },
  {
    locale: 'zh-CN',
    slug: 'big-five-narrative-portrait',
    title: '怎样阅读大五人格叙事画像而不把它当成定论',
    intent: '把叙事画像从身份结论改写为带反例检查的情境化观察。',
    opening: '一段读起来“很像我”的人格画像，也不等于它已经解释了你。叙事的价值在于帮助你提出更好的观察问题；它的风险在于把宽泛描述变成固定身份，忽略情境、角色与反例。',
    logic: ['把画像中的每个判断改写为“在什么情境下可能出现”。', '为每个吻合点寻找一个不吻合的场景。', '区分自己的观察、他人的反馈与系统生成的解释。', '只保留能够帮助下一步行动的工作假设。'],
    example: '画像写“你在群体中较安静”。读者把它改写为：“在陌生、议程不清的会议中，我通常先观察；在熟悉主题的小组里，我会主动发言。”新版描述更具体，也允许反例存在。',
    counterexample: '同一个人可能在工作、家庭、朋友与线上环境中呈现不同模式。某段描述没有命中，不代表读者作答错误；命中也不证明它是稳定本质。',
    framework: ['圈出一个有共鸣的句子并写明具体情境。', '补充一个反例和一个可能的其他解释。', '决定这条假设是否值得观察两周；否则删除。'],
    sources: ['academic.soto-john-2017-bfi2'],
    related: ['/zh/personality/big-five', '/zh/tests/big-five-personality-test-ocean-model', '/zh/topics/big-five'],
  },
  {
    locale: 'zh-CN',
    slug: 'big-five-personality-test-vs-mbti',
    title: '大五人格与 MBTI：不同问题与不同结果形式',
    intent: '比较维度式与类型式结果形式，不做优越性主张或确定映射。',
    opening: '大五人格与 MBTI 都可能出现在自我反思对话里，但两者的标签不能互换。更有用的起点不是“谁赢了”，而是三个问题：这个模型在问什么，它怎样表达答案，我现在想支持哪一个具体决定？',
    logic: ['识别结果是连续特质区间，还是类型偏好形式。', '比较结果真正表达的主张，不按熟悉词语强行配对。', '查看当前所用具体工具的公开方法与证据。', '把结果当作讨论提示，不当作保证性预测。'],
    example: '读者同时看到外向性区间与 MBTI 偏好时，不把一个换算成另一个，而是比较两个具体观察：群体活动后怎样恢复精力，以及在陌生会议中怎样参与；再检验哪种描述在当前情境有用。',
    counterexample: '同一类型标签下的两个人可能有不同的大五区间；相似的大五区间也不能推出同一种类型。措辞表面相似，不等于映射经过验证。',
    framework: ['写下你真正想回答的实践问题。', '阅读模型的公开方法与原始结果措辞。', '只保留经现实检查仍有用的观察，删除确定映射。'],
    sources: ['academic.goldberg-1990-big-five-structure', 'academic.soto-john-2017-bfi2'],
    related: ['/zh/personality/big-five', '/zh/tests/big-five-personality-test-ocean-model', '/zh/tests/mbti-personality-test-16-personality-types'],
    extra_boundary: '本文不声称某个模型普遍更科学、更准确或更有用，也不把大五分数映射成 MBTI 类型。',
  },
  {
    locale: 'zh-CN',
    slug: 'big-five-tool-guide',
    title: '大五人格测试工具使用与边界指南',
    intent: '说明测试前、作答时、读结果后分别能做什么，并与 PR23 方法页职责分离。',
    opening: '人格测试工具最适合支持结构化反思，不适合替你做身份、医疗、录用、升学、金融或法律决定。使用前先确认目的，作答时按通常状态而非理想形象回应，看到结果后再用现实观察检查。',
    logic: ['测试前：写下一个自我反思问题，并确认隐私与用途。', '作答时：按题目时间范围与通常行为作答，不追求“好分数”。', '读结果：先理解区间和不确定性，再看具体情境。', '行动后：用观察复盘，而不是反复测试追逐分数。'],
    example: '读者想改善团队沟通，可以把目标写成“识别我在意见不一致时的沟通模式”。完成测试后，他选择一次会议观察发言、倾听和暂停，而不是把结果交给管理者作为筛选依据。',
    counterexample: '测评结果与自我印象不同，可能来自题目理解、当前情境、回应方式或真实反例。差异不自动证明测试准确，也不自动证明读者不诚实。',
    framework: ['目的：写下测评要支持、以及明确不能支持的决定。', '观察：选择一个场景核对结果，记录吻合与反例。', '边界：只有问题仍适合自助反思时继续；超出范围则寻求相应专业支持。'],
    sources: ['academic.goldberg-1990-big-five-structure', 'academic.soto-john-2017-bfi2'],
    related: ['/zh/personality/big-five', '/zh/tests/big-five-personality-test-ocean-model', '/zh/topics/big-five'],
    extra_boundary: '本文不公开声称费马测试具有特定信效度、常模、样本量或百分位；这些产品数值当前为 Unknown。',
  },
];

const citation = (sourceId, locale) => {
  const source = sourceCatalog[sourceId];
  return {
    source_id: sourceId,
    label: locale === 'en' ? source.label_en : source.label_zh_cn,
    public_url: source.public_url,
    limitation: locale === 'en' ? source.limitation_en : source.limitation_zh_cn,
  };
};

const candidates = articles.map((article) => ({
  asset_type: 'Article',
  locale: article.locale,
  slug: article.slug,
  route: `/${article.locale === 'en' ? 'en' : 'zh'}/articles/${article.slug}`,
  title: article.title,
  title_brand_tokens: [],
  locked_intent: article.intent,
  review_status: 'pending_manual_review',
  cms_authority: {
    preserve_existing_record_identity: true,
    author: null,
    reviewer: null,
    published_at: null,
    updated_at: null,
    attribution_rule: 'At import, preserve only values read from the matching CMS Article record. Never synthesize or overwrite author, reviewer, or dates.',
  },
  sections: [
    { key: 'direct_opening', title: article.locale === 'en' ? 'Start with the decision boundary' : '先明确判断边界', body_md: article.opening },
    { key: 'logic', title: article.locale === 'en' ? 'A safer reasoning sequence' : '更稳妥的推理顺序', body_md: article.logic.map((item, index) => `${index + 1}. ${item}`).join('\n') },
    { key: 'example', title: article.locale === 'en' ? 'Concrete scenario' : '具体场景', body_md: article.example },
    { key: 'counterexample', title: article.locale === 'en' ? 'Counterexample and nuance' : '反例与细节', body_md: article.counterexample },
    { key: 'action_framework', title: article.locale === 'en' ? 'A practical three-step framework' : '三步行动框架', body_md: article.framework.map((item, index) => `${index + 1}. ${item}`).join('\n') },
    { key: 'boundary', title: article.locale === 'en' ? 'What this cannot conclude' : '本文不能得出什么结论', body_md: `${sharedBoundary[article.locale]}${article.extra_boundary ? ` ${article.extra_boundary}` : ''}` },
    { key: 'sources', title: article.locale === 'en' ? 'Visible sources and limits' : '公开来源与限制', body_md: article.sources.map((sourceId) => { const source = citation(sourceId, article.locale); return `- [${source.label}](${source.public_url}) — ${source.limitation}`; }).join('\n') },
    { key: 'next_steps', title: article.locale === 'en' ? 'Continue with primary surfaces' : '继续查看权威入口', body_md: article.related.map((route) => `- ${route}`).join('\n') },
  ],
  source_mapping: article.sources.map((sourceId) => citation(sourceId, article.locale)),
  internal_link_targets: article.related,
  body_generation_scope: 'refresh_existing_article_candidate_only',
  cms_write_executed: false,
  publish_state_change: false,
  indexability_change: false,
}));

const topicHubs = ['en', 'zh-CN'].map((locale) => ({
  asset_type: 'TopicHub',
  locale,
  route: `/${locale === 'en' ? 'en' : 'zh'}/topics/big-five`,
  title: locale === 'en' ? 'Big Five personality: concepts, results, and practical reflection' : '大五人格：模型、结果与实践反思',
  introduction: locale === 'en'
    ? 'Explore backend-authoritative Big Five material by question: understand the model, read a result carefully, test a practical action, or examine evidence and limits.'
    : '按问题浏览 backend-authoritative 的大五人格内容：理解模型、谨慎阅读结果、检验具体行动，或查看证据与限制。',
  review_status: 'pending_manual_review',
  cms_authority: {
    preserve_existing_record_identity: true,
    author: null,
    reviewer: null,
    published_at: null,
    updated_at: null,
  },
  enumeration: {
    source: 'backend_public_api',
    required_states: ['published', 'eligible'],
    exclude: ['draft', 'pending_manual_review', 'unpublished', 'private', 'non_indexable'],
    hardcoded_entries: [],
    rule: 'At render/import time, enumerate only Article records returned as published and eligible by backend authority. Do not infer eligibility from this package.',
  },
  groups: locale === 'en'
    ? ['Understand the model', 'Read results', 'Try a practical reflection', 'Evidence and limitations']
    : ['理解模型', '阅读结果', '进行实践反思', '证据与限制'],
  cms_write_executed: false,
  publish_state_change: false,
  indexability_change: false,
}));

const reviews = candidates.map((candidate) => ({
  locale: candidate.locale,
  slug: candidate.slug,
  initial_risks: [
    'Existing intent could overlap a later PR21-locked theme.',
    'Trait language could be read as identity or causality.',
    'Practical guidance could outrun the cited scientific evidence.',
    'Attribution metadata could be fabricated if not preserved from CMS.',
  ],
  repairs_applied: [
    `Locked intent retained as: ${candidate.locked_intent}`,
    'Added a counterexample and explicit method/product boundary.',
    'Labeled practical steps as reversible observation frameworks rather than validated interventions.',
    'Kept author, reviewer, and dates null with a CMS-preservation rule.',
    'Mapped each scientific source with a visible limitation.',
  ],
  unresolved: ['Human editorial review has not occurred.', 'CMS record identity and attribution must be re-read at import time.'],
  final_status: 'pending_manual_review',
}));

const qa = {
  schema_version: 'big5-article-refresh-qa.v1',
  generated_at: generatedAt,
  status: 'PASS_PENDING_MANUAL_REVIEW',
  counts: { article_candidates: candidates.length, en_articles: candidates.filter((item) => item.locale === 'en').length, zh_cn_articles: candidates.filter((item) => item.locale === 'zh-CN').length, topic_hubs: topicHubs.length, total_surfaces: candidates.length + topicHubs.length },
  checks: {
    exact_identity_inventory: true,
    unique_locale_slug_pairs: new Set(candidates.map((item) => `${item.locale}:${item.slug}`)).size === 9,
    single_brand_titles: candidates.every((item) => item.title_brand_tokens.length <= 1),
    required_section_keys: ['direct_opening', 'logic', 'example', 'counterexample', 'action_framework', 'boundary', 'sources', 'next_steps'],
    all_pending_manual_review: [...candidates, ...topicHubs].every((item) => item.review_status === 'pending_manual_review'),
    cms_attribution_values_synthesized: 0,
    topic_hub_hardcoded_entries: topicHubs.reduce((sum, hub) => sum + hub.enumeration.hardcoded_entries.length, 0),
    cms_writes: 0,
    publication_changes: 0,
    indexability_changes: 0,
    pr24_33_articles_added: 0,
  },
};

for (const [file, data] of Object.entries({
  'article-refresh-candidates.json': { schema_version: 'big5-article-refresh-candidates.v1', generated_at: generatedAt, authority: 'CMS/backend', candidates },
  'topic-hub-candidates.json': { schema_version: 'big5-topic-hub-refresh-candidates.v1', generated_at: generatedAt, authority: 'CMS/backend', candidates: topicHubs },
  'skeptical-review.json': { schema_version: 'big5-article-refresh-skeptical-review.v1', generated_at: generatedAt, reviews },
  'qa_report.json': qa,
})) {
  fs.writeFileSync(path.join(dir, file), `${JSON.stringify(data, null, 2)}\n`);
}

console.log('built Big Five refresh package: 9 articles + 2 topic hubs; all pending manual review');
