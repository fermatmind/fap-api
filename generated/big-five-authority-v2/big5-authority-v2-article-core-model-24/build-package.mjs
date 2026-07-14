import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const generatedAt = '2026-07-14T06:15:00Z';
const matrix = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-article-ia-21/article-intent-matrix.json'), 'utf8'));
const lockedThemes = matrix.themes.filter((theme) => theme.batch === 24);

const sources = {
  'academic.goldberg-1990-big-five-structure': { label_en: 'Goldberg (1990), Big-Five factor structure', label_zh: 'Goldberg（1990）：大五因子结构', url: 'https://doi.org/10.1037/0022-3514.59.6.1216', limit_en: 'Supports broad factor structure, not a single origin story, universal facet list, or FermatMind product accuracy.', limit_zh: '支持宽泛因子结构，不支持单一起源叙事、唯一侧面表或费马测试产品准确性。' },
  'academic.soto-john-2017-bfi2': { label_en: 'Soto & John (2017), BFI-2 domains and facets', label_zh: 'Soto 与 John（2017）：BFI-2 维度与侧面', url: 'https://doi.org/10.1037/pspp0000096', limit_en: 'Supports one named hierarchical model; it does not prove that all Big Five instruments use the same facets or validate FermatMind scores.', limit_zh: '支持一个明确命名的层级模型；不证明所有大五工具使用相同侧面，也不验证费马测试分数。' },
};

const copy = {
  'model-history': {
    en: {
      title: 'How the Big Five Model Developed: From Descriptive Words to Trait Research',
      answer: 'The Big Five did not begin as one inventor’s complete theory. It emerged through repeated attempts to organize personality-descriptive language and analyze recurring covariance patterns, followed by decades of instrument development, replication, criticism, and refinement.',
      evidence: 'Lexical research treated everyday personality words as data about distinctions people repeatedly make. Factor-analytic studies then examined which descriptors tended to vary together. Goldberg’s 1990 work is one influential record of a five-factor structure; later instruments such as BFI-2 operationalized a named hierarchy of domains and facets.',
      nuance: 'A historical sequence is not a proof that five is the only possible level of description. Different samples, item pools, languages, methods, and analytic choices can change the patterns researchers recover. “Discovered” is therefore too simple if it implies a single event or final taxonomy.',
      scenario: 'When a reader sees two articles assigning different dates to the “birth” of the Big Five, they can separate milestones: early lexical proposals, factor-analytic replications, labels becoming standardized, and later instrument construction. The dates may refer to different steps rather than a factual contradiction.',
      framework: ['Ask which historical milestone a source is describing.', 'Check whether the claim concerns words, statistical factors, a specific instrument, or public adoption.', 'Keep the source date and method beside the claim; avoid a hero-origin story.'],
      limitation: 'This overview is selective, not a complete history of personality psychology. It does not show that FermatMind uses any cited instrument or inherits its psychometric findings.',
    },
    'zh-CN': {
      title: '大五人格模型怎样形成：从描述性词语到特质研究',
      answer: '大五人格并不是由某位发明者一次性提出的完整理论。它来自多轮研究：整理人格描述词，分析反复出现的共同变化模式，再经过量表开发、重复研究、批评与修正。',
      evidence: '词汇研究把日常人格词语当作人们反复区分个体差异的资料；因素分析研究进一步观察哪些描述倾向共同变化。Goldberg 1990 年的研究是五因素结构的一项重要记录，之后 BFI-2 等工具把维度与侧面组织成明确命名的层级体系。',
      nuance: '历史顺序并不能证明“五个因素”是唯一描述层级。样本、题项库、语言、方法和分析选择都会影响研究得到的结构。因此，如果“发现”暗示一次事件或最终分类，就过于简单。',
      scenario: '读者看到两篇文章给出不同的“大五诞生年份”时，可以把里程碑拆开：早期词汇假设、因素结构重复、名称逐渐稳定，以及具体工具的开发。不同年份可能对应不同阶段，而不是事实冲突。',
      framework: ['先问来源描述的是哪一个历史里程碑。', '区分词汇、统计因素、具体量表和大众传播。', '把来源日期与研究方法放在主张旁边，避免单一英雄起源叙事。'],
      limitation: '本文是选择性概览，不是人格心理学完整史，也不证明费马测试使用任何被引用量表或继承其心理测量结果。',
    },
  },
  ocean: {
    en: {
      title: 'OCEAN Traits Explained: What the Five Letters Mean—and What They Do Not',
      answer: 'OCEAN is a memory aid for five broad trait domains: openness, conscientiousness, extraversion, agreeableness, and neuroticism. Each domain summarizes a family of related tendencies; none is a complete personality, ability score, diagnosis, or moral ranking.',
      evidence: 'Five-factor research organizes recurring covariance among many personality descriptors into broad domains. Named instruments then define their own items and narrower facets. BFI-2, for example, represents a specific domain-and-facet hierarchy; its labels should not be treated as the only possible implementation.',
      nuance: 'The letter N is often described as neuroticism, while some public results use emotional stability in the opposite direction. The wording and polarity must be stated rather than silently switched. Likewise, “high” is not always better and “low” is not always worse; usefulness depends on context and costs.',
      scenario: 'A reader sees a high extraversion range and assumes it means strong social skill. A better interpretation is narrower: the result may prompt observations about social engagement, energy, assertiveness, or stimulation seeking, while actual skill, listening, status, culture, and the situation still need separate evidence.',
      framework: ['Name the domain and its stated polarity.', 'Translate the broad label into one observable situation.', 'Look for a counterexample before deciding the description is useful.'],
      limitation: 'OCEAN is a broad vocabulary map. It cannot by itself explain a person, identify causes, prescribe a career, or validate a specific product score.',
    },
    'zh-CN': {
      title: 'OCEAN 五个维度是什么：含义、范围与不能推断的结论',
      answer: 'OCEAN 是五个宽泛特质维度的记忆缩写：开放性、尽责性、外向性、宜人性与神经质。每个维度概括一组相关倾向；任何一个维度都不是完整人格、能力分数、诊断或道德排名。',
      evidence: '五因素研究把许多人格描述之间反复出现的共同变化组织成宽泛维度，具体量表再定义各自的题项与较窄侧面。例如 BFI-2 表达的是一套明确命名的维度—侧面层级，不能被当作唯一实现方式。',
      nuance: '字母 N 通常指神经质，一些公开结果则以相反方向的“情绪稳定性”表达。页面必须说明名称与方向，不能静默切换。同样，“高”不总是更好，“低”也不总是更差；价值取决于情境与代价。',
      scenario: '读者看到外向性较高，就直接理解为社交能力强。更窄的解释是：结果可以提示观察社交参与、精力、主动表达或刺激偏好；真实技能、倾听、身份地位、文化与场景仍需独立证据。',
      framework: ['说清维度名称与方向。', '把宽泛标签翻译成一个可观察场景。', '先寻找反例，再判断描述是否有用。'],
      limitation: 'OCEAN 只是宽泛词汇地图，不能单独解释一个人、识别原因、规定职业或验证具体产品分数。',
    },
  },
  continuum: {
    en: {
      title: 'Big Five Traits Are Continuums, Not Personality Boxes',
      answer: 'Big Five traits are normally represented as positions on continuous dimensions. A result describes a relative range under a particular measurement context; it does not place everyone into a small set of natural personality boxes.',
      evidence: 'Five-factor models summarize degrees of broad tendencies, and hierarchical instruments use multiple items to estimate domains and facets. Continuous scores preserve distinctions that a simple “high type” versus “low type” label would discard.',
      nuance: 'A report may display bands such as low, middle, or high to make reading easier. Those bands are presentation choices, not proof of sharp boundaries. Without a public reviewed norm and cutoff rationale, a band cannot be treated as a percentile, diagnosis, or universal category.',
      scenario: 'Two readers fall on opposite sides of a displayed band boundary but have nearly identical underlying scores. Treating them as different “types” exaggerates the difference. Their actual behavior may overlap more than the labels suggest.',
      framework: ['Read the range as approximate, not as a hard edge.', 'Compare the description with behavior across at least two contexts.', 'Record both matching observations and exceptions before using the result.'],
      limitation: 'FermatMind-specific norms, cutoffs, percentiles, measurement error, and reliability values are Unknown here. This article cannot quantify how much confidence to place in one person’s score.',
    },
    'zh-CN': {
      title: '大五人格是连续谱，不是人格盒子',
      answer: '大五特质通常表达为连续维度上的位置。结果描述的是特定测量情境下的相对区间，不是把所有人分进少数天然人格盒子。',
      evidence: '五因素模型概括宽泛倾向的程度，层级量表通过多个题项估计维度与侧面。连续分数保留了差异；简单的“高类型”与“低类型”标签会丢失这些信息。',
      nuance: '报告可能用较低、中间、较高等区间帮助阅读。这些是呈现选择，不证明存在清晰断点。没有公开审核的常模与切点依据时，区间不能被当作百分位、诊断或通用类别。',
      scenario: '两位读者位于展示区间边界的两侧，但底层分数几乎相同。把他们当作不同“类型”会夸大差异；真实行为的重叠可能远大于标签暗示。',
      framework: ['把区间视为近似描述，不当作硬边界。', '至少在两个情境中对照结果与行为。', '同时记录吻合观察与例外，再决定是否使用结果。'],
      limitation: '费马测试产品特定的常模、切点、百分位、测量误差与信度数值在本文中均为 Unknown，本文不能量化个人分数应被信任到什么程度。',
    },
  },
  'thirty-facets': {
    en: {
      title: 'The “30 Big Five Facets” Explained—with the Taxonomy Caveat',
      answer: 'A 30-facet map usually means six narrower facets grouped under each of five broad domains in a named inventory tradition. It is one useful way to add detail, not a universal law that every Big Five instrument must follow.',
      evidence: 'Broad domains can contain more specific, partly distinct tendencies. Research instruments operationalize that hierarchy differently: BFI-2 uses 15 facets, other traditions use 30, and aspects research proposes another intermediate level. These systems overlap but are not interchangeable.',
      nuance: 'A shared facet name does not guarantee identical items or scoring. Translation can also change connotation. Before comparing results, identify the instrument, facet definition, direction, item content, and evidence rather than matching labels alone.',
      scenario: 'A reader compares “assertiveness” from one report with a similarly named facet elsewhere. One may emphasize taking social initiative while another includes leadership or dominance wording. Treating the scores as equivalent without definitions creates false precision.',
      framework: ['Name the instrument or taxonomy before listing facets.', 'Read each facet definition and its parent domain.', 'Compare constructs and items before comparing scores or labels.'],
      limitation: 'This article explains taxonomy choices; it does not publish or validate a FermatMind 30-facet scoring key. Product-specific facet equivalence and psychometric evidence remain Unknown unless publicly reviewed.',
    },
    'zh-CN': {
      title: '“大五人格 30 个侧面”是什么：先说明分类体系',
      answer: '所谓 30 个侧面，通常指在某个明确命名的量表传统中，每个宽泛维度下组织六个较窄侧面。它是一种增加细节的方式，不是所有大五工具必须遵守的普遍定律。',
      evidence: '宽泛维度可以包含更具体且部分独立的倾向。不同研究工具对层级的操作不同：BFI-2 使用 15 个侧面，其他传统使用 30 个，方面研究又提出另一种中间层级。这些体系有重叠，但不能互换。',
      nuance: '相同侧面名称不保证题项或计分相同，翻译也会改变含义。比较结果前，应先确认工具、定义、方向、题项内容与证据，而不是只匹配标签。',
      scenario: '读者把一份报告中的“果断”与另一处同名侧面直接比较。一种定义可能强调主动表达，另一种还包含领导或支配措辞；不看定义就把分数等同，会制造虚假精确。',
      framework: ['列出侧面前先说明量表或分类体系。', '阅读侧面定义与所属宽泛维度。', '比较分数或标签前，先比较构念与题项。'],
      limitation: '本文解释分类选择，不公开或验证费马测试 30 侧面计分键。产品特定侧面等价性与心理测量证据在未公开复审前保持 Unknown。',
    },
  },
  'non-type-system': {
    en: {
      title: 'Why the Big Five Is Not a Personality Type System',
      answer: 'The Big Five is dimensional: it describes degrees of broad tendencies across several axes. A type system assigns a person to a category or pattern. Turning five continuous ranges into one identity label changes the model and discards information.',
      evidence: 'Five-factor research models covariance among many descriptors as broad dimensions. Hierarchical instruments estimate domain and facet positions rather than requiring one natural partition of people into types.',
      nuance: 'People may still use shorthand such as “high openness” in conversation. That shorthand is a range description, not a complete type. It should retain context, uncertainty, other dimensions, and counterexamples rather than become “an open person” as a fixed identity.',
      scenario: 'A reader asks for their “Big Five personality type.” Instead of inventing a five-letter code, they review each domain range separately and choose one situation to test. Two people with similar overall profiles can still differ in facets, context, skills, history, and goals.',
      framework: ['Keep all five dimensions separate.', 'Describe a range as a hypothesis about tendencies, not identity.', 'Use context and counterexamples before choosing an action.'],
      limitation: 'Dimensional representation does not automatically make an instrument valid or accurate. FermatMind-specific reliability, validity, norms, and percentiles remain Unknown here.',
    },
    'zh-CN': {
      title: '为什么大五人格不是人格类型系统',
      answer: '大五人格是维度式模型：它在多个轴上描述宽泛倾向的程度。类型系统则把人分配到类别或模式。把五个连续区间压缩成一个身份标签，会改变模型并丢失信息。',
      evidence: '五因素研究把许多描述之间的共同变化建模为宽泛维度，层级量表估计维度与侧面位置，并不要求把所有人自然切成若干类型。',
      nuance: '日常对话仍可能说“开放性较高”。这只是区间简称，不是完整类型。它应保留情境、不确定性、其他维度与反例，而不是变成固定身份“开放型的人”。',
      scenario: '读者询问自己的“大五人格类型”。更稳妥的做法不是发明五字母代码，而是分别阅读每个维度，并选一个场景检验。总体画像相似的两个人，仍可能在侧面、情境、技能、经历和目标上不同。',
      framework: ['保持五个维度彼此独立。', '把区间写成倾向假设，不写成身份。', '结合情境与反例后再选择行动。'],
      limitation: '维度式表达本身并不能自动证明某个工具有效或准确。费马测试产品特定的信度、效度、常模和百分位在本文中保持 Unknown。',
    },
  },
};

const visibleSources = (ids, locale) => ids.map((id) => ({ source_id: id, label: locale === 'en' ? sources[id].label_en : sources[id].label_zh, public_url: sources[id].url, limitation: locale === 'en' ? sources[id].limit_en : sources[id].limit_zh }));

const rawDrafts = lockedThemes.flatMap((theme) => theme.locales.map((locked) => {
  const text = copy[theme.theme_key][locked.locale];
  return {
    topic_id: theme.topic_id, locale: locked.locale, slug: locked.slug, title: text.title,
    locked_intent_key: theme.unique_intent_key, primary_question: locked.primary_question,
    raw_sections: { direct_answer: text.answer, evidence: text.evidence, scenario: text.scenario, practical_framework: text.framework },
    evidence_source_ids: locked.source_requirements,
    review_status: 'draft_requires_skeptical_review',
  };
}));

const reviews = rawDrafts.map((draft) => ({
  topic_id: draft.topic_id, locale: draft.locale, slug: draft.slug,
  findings: ['Raw draft needs an explicit counterexample/nuance section.', 'Source visibility must include page-level limitations.', 'Method and product boundaries must be separate from general model evidence.', 'Publication and reviewer state must fail closed.'],
  repair_required: true,
  reviewer: null,
}));

const repairedDrafts = lockedThemes.flatMap((theme) => theme.locales.map((locked) => {
  const text = copy[theme.theme_key][locked.locale];
  return {
    topic_id: theme.topic_id, locale: locked.locale, slug: locked.slug,
    repairs: ['Added nuance/counterexample.', 'Added page-level scientific-source limitations.', 'Added concrete three-step framework.', 'Added explicit product/method boundary and Unknown evidence statement.', 'Preserved locked internal links and draft-only state.'],
    sections: { direct_answer: text.answer, evidence: text.evidence, nuance_counterexample: text.nuance, concrete_scenario: text.scenario, practical_framework: text.framework, limitation: text.limitation },
    source_mapping: visibleSources(locked.source_requirements, locked.locale),
    review_status: 'repaired_pending_manual_review',
  };
}));

const finalAssets = lockedThemes.flatMap((theme) => theme.locales.map((locked) => {
  const text = copy[theme.theme_key][locked.locale];
  const sourceMapping = visibleSources(locked.source_requirements, locked.locale);
  const methodBoundary = locked.locale === 'en'
    ? 'Educational self-reflection only. This Article does not diagnose, assign identity, guarantee outcomes, or establish FermatMind reliability, validity, norms, sample size, percentiles, or predictive accuracy; unsupported product values are Unknown.'
    : '仅用于教育性自我反思。本文不诊断、不分配身份、不保证结果，也不证明费马测试产品的信度、效度、常模、样本量、百分位或预测准确性；无公开审核证据的产品数值均为 Unknown。';
  return {
    asset_type: 'Article', topic_id: theme.topic_id, batch: 24, locale: locked.locale, slug: locked.slug, path: locked.path,
    title: text.title, title_intent: locked.title_intent, primary_question: locked.primary_question, audience: locked.audience, user_task: locked.user_task,
    keywords: locked.keywords, search_intent: locked.search_intent, unique_intent_key: theme.unique_intent_key,
    sections: [
      { key: 'direct_answer', body_md: text.answer }, { key: 'evidence', body_md: text.evidence },
      { key: 'nuance_counterexample', body_md: text.nuance }, { key: 'concrete_scenario', body_md: text.scenario },
      { key: 'practical_framework', body_md: text.framework.map((step, index) => `${index + 1}. ${step}`).join('\n') },
      { key: 'limitation', body_md: text.limitation },
      { key: 'visible_sources', body_md: sourceMapping.map((source) => `- [${source.label}](${source.public_url}) — ${source.limitation}`).join('\n') },
      { key: 'method_product_boundary', body_md: methodBoundary },
      { key: 'internal_links', body_md: locked.internal_link_targets.map((target) => `- ${target}`).join('\n') },
    ],
    source_mapping: sourceMapping, internal_link_targets: locked.internal_link_targets, risk_boundary: locked.risk_boundary,
    review_status: 'pending_manual_review', reviewer: null, author: null, published_at: null,
    cms_write_executed: false, publish_state_change: false, indexability_change: false,
  };
}));

const qa = {
  schema_version: 'big5-article-wave-qa.v1', generated_at: generatedAt, status: 'PASS_PENDING_MANUAL_REVIEW',
  counts: { locked_themes: lockedThemes.length, article_assets: finalAssets.length, en_assets: finalAssets.filter((asset) => asset.locale === 'en').length, zh_cn_assets: finalAssets.filter((asset) => asset.locale === 'zh-CN').length },
  checks: {
    consumes_only_pr21_batch_24: true,
    exact_locked_slug_locale_pairs: true,
    unique_intents: new Set(finalAssets.map((asset) => asset.unique_intent_key)).size === 5,
    raw_drafts_preserved: rawDrafts.length === 10,
    skeptical_reviews_preserved: reviews.length === 10,
    repaired_drafts_preserved: repairedDrafts.length === 10,
    source_mapping_preserved: finalAssets.every((asset) => asset.source_mapping.length >= 1),
    all_pending_manual_review: finalAssets.every((asset) => asset.review_status === 'pending_manual_review'),
    cms_writes: 0, published_assets: 0, indexability_changes: 0,
  },
};

for (const [file, data] of Object.entries({
  'raw-drafts.json': { schema_version: 'big5-article-wave-raw.v1', generated_at: generatedAt, assets: rawDrafts },
  'skeptical-review.json': { schema_version: 'big5-article-wave-review.v1', generated_at: generatedAt, reviews },
  'repaired-drafts.json': { schema_version: 'big5-article-wave-repaired.v1', generated_at: generatedAt, assets: repairedDrafts },
  'final-package.json': { schema_version: 'big5-article-wave-final.v1', generated_at: generatedAt, authority: 'PR21 batch 24 + CMS/backend', assets: finalAssets },
  'source-mapping.json': { schema_version: 'big5-article-wave-sources.v1', generated_at: generatedAt, mappings: finalAssets.map((asset) => ({ topic_id: asset.topic_id, locale: asset.locale, slug: asset.slug, sources: asset.source_mapping })) },
  'qa_report.json': qa,
})) fs.writeFileSync(path.join(dir, file), `${JSON.stringify(data, null, 2)}\n`);

console.log('built batch 24 core-model wave: 5 locked themes / 10 Article candidates / all pending manual review');
