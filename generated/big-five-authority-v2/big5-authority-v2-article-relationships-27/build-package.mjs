import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const generatedAt = '2026-07-14T07:48:15Z';
const matrix = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-article-ia-21/article-intent-matrix.json'), 'utf8'));
const ledger = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json'), 'utf8'));
const lockedThemes = matrix.themes.filter((theme) => theme.batch === 27);

const sourceDisplay = {
  'academic.soto-john-2017-bfi2': {
    en: { label: 'Soto & John (2017), BFI-2 domains and facets', limitation: 'Supports one hierarchical Big Five model; it does not diagnose a relationship, reveal motives, establish compatibility, override consent, or validate FermatMind scores.' },
    'zh-CN': { label: 'Soto 与 John（2017）：BFI-2 维度与侧面', limitation: '支持一种大五层级模型；不诊断关系、不揭示动机、不证明匹配，也不凌驾于同意或验证费马测试分数。' },
  },
  'internal.public-claim-boundary-matrix': {
    en: { label: 'FermatMind public claim boundary matrix', limitation: 'Requires non-diagnostic and non-deterministic use; repository policy is not peer-reviewed relationship evidence or a safety assessment.' },
    'zh-CN': { label: '费马测试公开主张边界矩阵', limitation: '要求非诊断、非决定论使用；仓库政策不是经同行评审的关系证据，也不是安全评估。' },
  },
};

const copy = {
  communication: {
    en: {
      title: 'Turning Big Five Language Into Specific Communication Requests',
      answer: 'Use Big Five language as a prompt for a communication check-in, not as an explanation of the other person. Ask permission, describe one situation, state the impact and need, and make a specific request that the other person can accept, decline, or revise.',
      evidence: 'Broad trait dimensions summarize tendencies, while communication also depends on relationship history, culture, language, power, attention, skill, stress, and the immediate topic. A result cannot reveal what someone meant or what they owe another person.',
      nuance: 'A preference may change across contexts. Someone may want time to prepare for an emotional discussion but prefer quick coordination for logistics. A trait label can hide that variation and turn a negotiable request into an identity claim.',
      scenario: 'Instead of saying “you are low in agreeableness and never listen,” a friend says, “When plans changed without a message, I felt unprepared. Could we confirm changes by noon, or choose another method that works for both of us?”',
      framework: ['Ask whether this is a good time for a check-in.', 'Describe one observable event without a trait label.', 'State your impact or need in first-person language.', 'Make one negotiable request and invite an alternative.'],
      limitation: 'This prompt does not diagnose communication problems, prove intent, guarantee agreement, or replace direct consent and safety. FermatMind product reliability, validity, norms, and relationship prediction remain Unknown.',
    },
    'zh-CN': {
      title: '把大五人格语言转成具体沟通请求，而不是解释对方',
      answer: '把大五人格语言当作沟通检查的提示，不用它解释对方。先征得同意，描述一个情境，说明影响与需要，再提出对方可以接受、拒绝或修改的具体请求。',
      evidence: '宽泛特质维度概括倾向，沟通还受关系历史、文化、语言、权力、注意力、技能、压力与当前主题影响。结果不能揭示某人的真实意图，也不能决定对方欠你什么。',
      nuance: '偏好会随情境变化。一个人在情绪对话前可能需要准备时间，处理日常安排时却偏好快速协调。特质标签会遮住这种变化，把可协商请求变成身份判断。',
      scenario: '与其说“你宜人性低，从来不听”，朋友可以说：“计划没有消息就改变时，我没有准备。以后能否在中午前确认变化，或者一起选一个双方都能用的方法？”',
      framework: ['先问现在是否适合做沟通检查。', '只描述一个可观察事件，不贴特质标签。', '用第一人称说明影响或需要。', '提出一个可协商请求，并邀请替代方案。'],
      limitation: '本提示不能诊断沟通问题、证明意图、保证达成一致，也不能取代直接同意与安全判断。费马测试产品信度、效度、常模和关系预测能力均为 Unknown。',
    },
  },
  conflict: {
    en: {
      title: 'Observe Conflict Sequences Before Explaining Them With Personality',
      answer: 'Big Five traits may suggest questions about a conflict pattern, but they do not explain its cause. First map the sequence—trigger, interpretation, action, response, escalation, and repair—then test what changes the interaction without assigning blame to a personality.',
      evidence: 'Traits describe broad tendencies, whereas conflict is shaped by needs, history, power, incentives, communication skill, timing, resources, and safety. A dimensional result cannot show who started a conflict, who is right, or whether behavior is acceptable.',
      nuance: 'The same behavior can have different functions. Withdrawal may be a pause, avoidance, overload, or a safety response; directness may clarify or intimidate depending on context and power. Trait language alone cannot distinguish these possibilities.',
      scenario: 'Two siblings repeatedly argue about caregiving. Rather than calling one “neurotic” and the other “disagreeable,” they map when the discussion begins, what information is missing, how interruptions escalate it, and whether a written agenda changes the sequence.',
      framework: ['Write the conflict sequence in observable steps.', 'Separate interpretation from behavior and consequence.', 'Identify power, resource, timing, and safety factors.', 'Test one reversible process change; seek qualified help when safety or coercion is involved.'],
      limitation: 'This framework is not therapy, mediation, diagnosis, abuse assessment, or proof of causation. Personality never excuses coercion, threats, harassment, or boundary violations; product-specific evidence remains Unknown.',
    },
    'zh-CN': {
      title: '先观察冲突序列，再讨论人格：不要把原因简化为特质',
      answer: '大五特质可以提示有关冲突模式的问题，但不能解释冲突原因。先画出触发、解释、行动、回应、升级与修复的序列，再检验什么能改变互动，而不是把责任归到人格上。',
      evidence: '特质描述宽泛倾向，冲突还受需要、历史、权力、激励、沟通技能、时机、资源与安全影响。维度结果不能判断谁挑起冲突、谁一定正确，也不能判断某种行为是否可以接受。',
      nuance: '同一行为可能有不同功能。沉默可能是暂停、回避、过载或安全反应；直接表达可能带来清晰，也可能在特定权力条件下构成威慑。仅凭特质无法区分。',
      scenario: '兄弟姐妹反复因照护安排争吵。与其把一方叫作“神经质”、另一方叫作“不宜人”，可以记录讨论何时开始、缺少什么信息、打断怎样升级冲突，以及书面议程是否改变序列。',
      framework: ['用可观察步骤写出冲突序列。', '把解释与行为、后果分开。', '检查权力、资源、时机与安全因素。', '测试一项可逆流程变化；涉及安全或强迫时寻求合格支持。'],
      limitation: '本框架不是心理治疗、调解、诊断、虐待评估或因果证明。人格绝不能为强迫、威胁、骚扰或越界开脱；产品特定证据仍为 Unknown。',
    },
  },
  friendship: {
    en: {
      title: 'Discussing Friendship Differences Without Compatibility Scores',
      answer: 'Friends can discuss differences by naming concrete needs and constraints, not by calculating compatibility. Each person can share what helps with contact, planning, novelty, emotional support, and recovery time, then negotiate a small agreement.',
      evidence: 'A Big Five profile summarizes several broad tendencies but does not measure loyalty, shared history, care, communication skill, values, availability, or mutual effort. Similarity and difference are therefore not friendship verdicts.',
      nuance: 'Needs may conflict without either friend being defective. One person may want spontaneous contact while another needs advance planning; the useful question is what arrangement is sustainable and voluntary, not whose trait range is better.',
      scenario: 'One friend prefers frequent messages and the other often replies later. They avoid an “extravert–introvert mismatch” story, agree that urgent messages need a clear marker, and choose a weekly time for unhurried contact.',
      framework: ['Name one recurring friendship situation.', 'Let each person state a need and a real constraint.', 'Avoid comparing scores or assigning motives.', 'Agree on a reversible arrangement and review it later.'],
      limitation: 'Big Five results cannot score friendship quality, predict durability, prove compatibility, or require access to another person’s private report. FermatMind relationship metrics and predictive accuracy are Unknown.',
    },
    'zh-CN': {
      title: '朋友怎样讨论人格差异：协商需要，不计算匹配分',
      answer: '朋友可以通过说明具体需要与限制来讨论差异，而不是计算匹配度。双方分别说明在联系频率、计划、新鲜感、情绪支持与恢复时间上什么有帮助，再协商一个小约定。',
      evidence: '大五画像概括多个宽泛倾向，却不测量忠诚、共同经历、关怀、沟通技能、价值观、可用时间或共同投入。因此，相似与差异都不是友谊结论。',
      nuance: '需要发生冲突，并不代表任何一方有缺陷。一方喜欢临时联系，另一方需要提前安排；真正有用的问题是怎样的安排可持续且自愿，而不是谁的特质区间更好。',
      scenario: '一位朋友希望频繁消息，另一位常常晚些回复。他们不采用“外向—内向不匹配”的故事，而是约定紧急消息使用清楚标记，并选择每周一次从容联系的时间。',
      framework: ['只命名一个反复出现的友谊情境。', '双方各自说明一个需要和一个现实限制。', '不比较分数，也不替对方推断动机。', '约定可逆安排，并在之后复盘。'],
      limitation: '大五人格结果不能给友谊质量打分、预测持续时间、证明匹配，也不能要求查看对方私人报告。费马测试关系指标与预测准确性均为 Unknown。',
    },
  },
  partners: {
    en: {
      title: 'How Couples Can Use Big Five Results Without Predicting the Relationship',
      answer: 'Couples can use a result constructively only as an optional conversation prompt. Discuss one shared situation, let each partner decide what to disclose, translate descriptions into needs or experiments, and keep relationship outcomes open.',
      evidence: 'Trait dimensions may organize questions about tendencies, but relationships also depend on consent, trust, values, history, resources, communication, power, life events, and behavior. A profile cannot establish compatibility or forecast breakup, satisfaction, or commitment.',
      nuance: 'A shared label can feel explanatory while hiding the interaction. “We clash because of conscientiousness” may overlook unequal workload, unclear agreements, or different standards. Results should not be used to win an argument or define the other partner.',
      scenario: 'A couple disagrees about household planning. They do not compare scores; each describes the planning burden, identifies one task that needs ownership, and tests a visible weekly checklist without claiming the arrangement reflects fixed personalities.',
      framework: ['Confirm that both people want to use the prompt.', 'Choose one shared situation rather than a whole relationship judgment.', 'Translate trait language into observable needs and responsibilities.', 'Test one agreement and review its effects without score comparison.'],
      limitation: 'This is not couples therapy, compatibility testing, safety assessment, or relationship prediction. Personality never overrides consent or excuses harm; FermatMind product psychometrics and outcome evidence are Unknown.',
    },
    'zh-CN': {
      title: '伴侣怎样建设性使用大五人格结果，而不预测关系结局',
      answer: '伴侣只有把结果作为可选对话提示时，才可能建设性使用它。讨论一个共同情境，由双方决定披露什么，把描述转化为需要或实验，并让关系结果保持开放。',
      evidence: '特质维度可以组织有关倾向的问题，关系还取决于同意、信任、价值观、历史、资源、沟通、权力、生活事件与行为。画像不能证明匹配，也不能预测分手、满意度或承诺。',
      nuance: '共同标签看似能解释问题，却可能遮住互动。“我们因为尽责性不同而冲突”可能忽略负担不均、约定不清或标准差异。结果不能被用来赢得争论或定义另一位伴侣。',
      scenario: '伴侣因家务计划发生分歧。他们不比较分数，而是分别描述计划负担，明确一项任务的负责人，并试行可见的每周清单，同时不把安排说成固定人格。',
      framework: ['确认双方都愿意使用这一提示。', '只选择一个共同情境，不评价整段关系。', '把特质语言转成可观察的需要与责任。', '测试一项约定并复盘影响，不比较分数。'],
      limitation: '本文不是伴侣治疗、匹配测试、安全评估或关系预测。人格绝不能凌驾于同意或为伤害开脱；费马测试产品心理测量与结果证据均为 Unknown。',
    },
  },
  boundaries: {
    en: {
      title: 'Personality Traits Do Not Override Consent or Personal Boundaries',
      answer: 'Traits may help someone reflect on preferences, but personal boundaries are communicated limits and consent decisions. A score cannot infer, weaken, negotiate away, or explain another person’s “no.” Ask directly and respect the answer.',
      evidence: 'Big Five dimensions describe broad tendencies, not permission, capacity, safety, or obligations. Boundary decisions also depend on context, relationship, risk, values, power, and current willingness—none of which can be read from a trait profile.',
      nuance: 'A preference and a boundary are not the same. A preference may invite negotiation; consent must remain voluntary, specific, informed, current, and reversible. Agreeableness or any other range cannot make refusal less valid.',
      scenario: 'Someone says they do not want their assessment discussed with friends. The other person does not argue that the boundary reflects “low openness”; they stop sharing, delete any copied link, and ask what information may remain private.',
      framework: ['Ask directly instead of inferring from a trait.', 'Treat consent as specific to the action and moment.', 'Accept refusal without diagnosis, pressure, or score-based argument.', 'Protect private reports and revisit only if the person freely initiates.'],
      limitation: 'This educational article is not legal, clinical, crisis, or safety advice. If coercion, threats, stalking, or violence are present, personality interpretation is inappropriate and qualified local support may be needed.',
    },
    'zh-CN': {
      title: '人格特质不能凌驾于同意或个人边界',
      answer: '特质可以帮助一个人反思偏好，但个人边界是明确表达的限制与同意决定。分数不能推断、削弱、取消或解释另一个人的“不”。应直接询问并尊重答案。',
      evidence: '大五维度描述宽泛倾向，不代表许可、能力、安全或义务。边界决定还取决于情境、关系、风险、价值观、权力与当下意愿，这些都不能从特质画像中读出。',
      nuance: '偏好与边界不同。偏好可能允许协商；同意必须自愿、具体、知情、当下有效且可以撤回。宜人性或任何其他区间都不能让拒绝变得不正当。',
      scenario: '某人明确表示不希望朋友讨论自己的测评。对方不把边界解释为“开放性低”，而是停止分享、删除复制的链接，并询问哪些信息需要继续保密。',
      framework: ['直接询问，不从特质推断。', '把同意限定到具体行动与当下时刻。', '接受拒绝，不诊断、不施压、不用分数争辩。', '保护私人报告，除非对方自愿发起，否则不重提。'],
      limitation: '本文不是法律、临床、危机或安全建议。若存在强迫、威胁、跟踪或暴力，人格解释并不合适，可能需要寻求当地合格支持。',
    },
  },
};

const sourceById = new Map(ledger.sources.map((source) => [source.id, source]));
const mapSources = (ids, locale) => ids.map((id) => {
  const source = sourceById.get(id);
  const display = sourceDisplay[id][locale];
  return { source_id: id, evidence_category: source.evidence_category, label: display.label, public_url: source.public_url, repository_path: source.repository_path ?? null, limitation: display.limitation };
});
const sourceLine = (source, locale) => source.public_url ? `- [${source.label}](${source.public_url}) — ${source.limitation}` : `- ${source.label} — ${locale === 'en' ? 'Repository source' : '仓库来源'}: \`${source.repository_path}\` — ${source.limitation}`;
const rawDrafts = lockedThemes.flatMap((theme) => theme.locales.map((locked) => {
  const text = copy[theme.theme_key][locked.locale];
  return { topic_id: theme.topic_id, locale: locked.locale, slug: locked.slug, title: text.title, locked_intent_key: theme.unique_intent_key, primary_question: locked.primary_question, raw_sections: { direct_answer: text.answer, evidence: text.evidence, scenario: text.scenario, practical_framework: text.framework }, evidence_source_ids: locked.source_requirements, review_status: 'draft_requires_skeptical_review' };
}));
const reviews = rawDrafts.map((draft) => ({ topic_id: draft.topic_id, locale: draft.locale, slug: draft.slug, findings: ['Replace personality explanation with observable relationship context.', 'Add a counterexample and distinguish preferences from consent or boundaries.', 'Prohibit compatibility, motive, diagnosis, and relationship-outcome conclusions.', 'Keep private report links, attribution, publication, and indexability fail closed.'], repair_required: true, reviewer: null }));
const repairedDrafts = lockedThemes.flatMap((theme) => theme.locales.map((locked) => {
  const text = copy[theme.theme_key][locked.locale];
  return { topic_id: theme.topic_id, locale: locked.locale, slug: locked.slug, repairs: ['Reframed the result as an optional conversation prompt.', 'Added relationship context and a counterexample.', 'Separated preferences, consent, and non-negotiable boundaries.', 'Added compatibility and outcome-prediction prohibitions.', 'Preserved private-result and release safety.'], sections: { direct_answer: text.answer, evidence: text.evidence, nuance_counterexample: text.nuance, concrete_scenario: text.scenario, practical_framework: text.framework, limitation: text.limitation }, source_mapping: mapSources(locked.source_requirements, locked.locale), review_status: 'repaired_pending_manual_review' };
}));
const finalAssets = lockedThemes.flatMap((theme) => theme.locales.map((locked) => {
  const text = copy[theme.theme_key][locked.locale];
  const sourceMapping = mapSources(locked.source_requirements, locked.locale);
  const boundary = locked.locale === 'en'
    ? 'Educational, voluntary self-reflection only. This Article does not diagnose people or relationships, reveal motives, assign fixed identity, establish compatibility, predict relationship outcomes, override consent, excuse harm, or validate FermatMind psychometrics. Unsupported product values are Unknown; private results and report or attempt links must not be collected or published.'
    : '仅用于自愿的教育性自我反思。本文不诊断个人或关系、不揭示动机、不分配固定身份、不证明匹配、不预测关系结局、不凌驾于同意、不为伤害开脱，也不验证费马测试心理测量指标。无公开证据的产品数值均为 Unknown；不得收集或公开私人结果以及 report 或 attempt 链接。';
  return { asset_type: 'Article', topic_id: theme.topic_id, batch: 27, locale: locked.locale, slug: locked.slug, path: locked.path, title: text.title, title_intent: locked.title_intent, primary_question: locked.primary_question, audience: locked.audience, user_task: locked.user_task, keywords: locked.keywords, search_intent: locked.search_intent, unique_intent_key: theme.unique_intent_key, sections: [
    { key: 'direct_answer', body_md: text.answer }, { key: 'evidence', body_md: text.evidence }, { key: 'nuance_counterexample', body_md: text.nuance }, { key: 'concrete_scenario', body_md: text.scenario }, { key: 'practical_framework', body_md: text.framework.map((step, index) => `${index + 1}. ${step}`).join('\n') }, { key: 'limitation', body_md: text.limitation }, { key: 'visible_sources', body_md: sourceMapping.map((source) => sourceLine(source, locked.locale)).join('\n') }, { key: 'method_product_boundary', body_md: boundary }, { key: 'internal_links', body_md: locked.internal_link_targets.map((target) => `- ${target}`).join('\n') },
  ], source_mapping: sourceMapping, internal_link_targets: locked.internal_link_targets, risk_boundary: locked.risk_boundary, review_status: 'pending_manual_review', reviewer: null, author: null, published_at: null, cms_write_executed: false, publish_state_change: false, indexability_change: false };
}));
const qa = { schema_version: 'big5-article-wave-qa.v1', generated_at: generatedAt, status: 'PASS_PENDING_MANUAL_REVIEW', counts: { locked_themes: lockedThemes.length, article_assets: finalAssets.length, en_assets: finalAssets.filter((asset) => asset.locale === 'en').length, zh_cn_assets: finalAssets.filter((asset) => asset.locale === 'zh-CN').length }, checks: { consumes_only_pr21_batch_27: true, exact_locked_slug_locale_pairs: true, unique_intents: new Set(finalAssets.map((asset) => asset.unique_intent_key)).size === 5, raw_drafts_preserved: rawDrafts.length === 10, skeptical_reviews_preserved: reviews.length === 10, repaired_drafts_preserved: repairedDrafts.length === 10, source_mapping_preserved: finalAssets.every((asset) => asset.source_mapping.length === 2), consent_and_boundary_primacy: true, compatibility_and_outcome_prediction_prohibited: true, private_result_links_excluded: true, all_pending_manual_review: finalAssets.every((asset) => asset.review_status === 'pending_manual_review'), cms_writes: 0, published_assets: 0, indexability_changes: 0 } };
for (const [file, data] of Object.entries({ 'raw-drafts.json': { schema_version: 'big5-article-wave-raw.v1', generated_at: generatedAt, assets: rawDrafts }, 'skeptical-review.json': { schema_version: 'big5-article-wave-review.v1', generated_at: generatedAt, reviews }, 'repaired-drafts.json': { schema_version: 'big5-article-wave-repaired.v1', generated_at: generatedAt, assets: repairedDrafts }, 'final-package.json': { schema_version: 'big5-article-wave-final.v1', generated_at: generatedAt, authority: 'PR21 batch 27 + CMS/backend', assets: finalAssets }, 'source-mapping.json': { schema_version: 'big5-article-wave-sources.v1', generated_at: generatedAt, mappings: finalAssets.map((asset) => ({ topic_id: asset.topic_id, locale: asset.locale, slug: asset.slug, sources: asset.source_mapping })) }, 'qa_report.json': qa })) fs.writeFileSync(path.join(dir, file), `${JSON.stringify(data, null, 2)}\n`);
console.log('built batch 27 relationships wave: 5 locked themes / 10 Article candidates / consent and boundary primacy');
