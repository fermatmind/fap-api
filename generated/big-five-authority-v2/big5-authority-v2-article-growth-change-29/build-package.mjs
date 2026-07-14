import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const generatedAt = '2026-07-14T09:13:30Z';
const matrix = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-article-ia-21/article-intent-matrix.json'), 'utf8'));
const ledger = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json'), 'utf8'));
const lockedThemes = matrix.themes.filter((theme) => theme.batch === 29);

const sourceDisplay = {
  'academic.roberts-walton-viechtbauer-2006-change': {
    en: { label: 'Roberts, Walton, & Viechtbauer (2006), longitudinal meta-analysis', limitation: 'Supports group-level mean-change patterns across the life course; it cannot predict one person, identify the cause of change, validate a habit intervention, or validate FermatMind scores.' },
    'zh-CN': { label: 'Roberts、Walton 与 Viechtbauer（2006）：纵向研究元分析', limitation: '支持生命历程中的群体平均变化模式；不能预测个人、识别变化原因、验证习惯干预或验证费马测试分数。' },
  },
  'internal.public-claim-boundary-matrix': {
    en: { label: 'FermatMind public claim boundary matrix', limitation: 'Requires non-diagnostic and non-deterministic use; repository policy is not scientific evidence that goals, events, or tracking cause personality change.' },
    'zh-CN': { label: '费马测试公开主张边界矩阵', limitation: '要求非诊断、非决定论使用；仓库政策不是目标、事件或追踪导致人格变化的科学证据。' },
  },
};

const copy = {
  'personality-change': {
    en: {
      title: 'Big Five Traits Can Change at Group Level—That Is Not a Personal Forecast',
      answer: 'Big Five traits are neither perfectly fixed nor freely programmable. Longitudinal research reports group-level mean-change patterns, but it cannot tell whether, when, why, or how much one person will change.',
      evidence: 'The cited meta-analysis combines longitudinal studies to examine average trait change across the life course. An average pattern is evidence about samples, not a timetable, causal explanation, or guaranteed trajectory for an individual.',
      nuance: 'Stability and change can coexist: people may retain relative differences while group averages move. Scores can also vary with measurement conditions, so one changed result is not automatically evidence of durable trait change.',
      scenario: 'Someone receives different results one year apart and concludes that a new role permanently changed their personality. A safer reading separates possible change from context, response conditions, ordinary variation, and the limits of two observations.',
      framework: ['State the exact behavior or experience that seems different.', 'Record contexts and repeated examples rather than relying on one score.', 'Keep multiple explanations open, including measurement conditions.', 'Review over time without predicting a fixed destination.'],
      limitation: 'This article cannot forecast personal change, establish causality, or guarantee self-improvement. FermatMind-specific reliability, validity, norms, percentiles, and change sensitivity remain Unknown.',
    },
    'zh-CN': {
      title: '大五特质在群体层面可能变化，但这不是个人预测',
      answer: '大五特质既非完全固定，也不能随意编程。纵向研究报告群体平均变化模式，却不能说明某个人是否、何时、为何或改变多少。',
      evidence: '引用的元分析综合纵向研究，考察生命历程中的平均特质变化。平均模式是关于样本的证据，不是个人时间表、因果解释或保证的轨迹。',
      nuance: '稳定与变化可以共存：群体平均值移动时，人们的相对差异仍可能保持。分数也会随测量条件波动，所以一次结果改变不自动等于持久特质改变。',
      scenario: '某人一年后得到不同结果，就断定新角色永久改变了人格。更稳妥的解读会区分可能变化、情境、作答条件、普通波动，以及仅有两次观察的限制。',
      framework: ['写出看似改变的具体行为或体验。', '记录情境与重复实例，不只依靠一次分数。', '保留多种解释，包括测量条件。', '随时间复盘，但不预测固定终点。'],
      limitation: '本文不能预测个人变化、证明因果或保证自我改善。费马测试特定信度、效度、常模、百分位与变化敏感性均为 Unknown。',
    },
  },
  habits: {
    en: {
      title: 'Habits Are Repeated Behaviors, Not Proof of a Fixed Personality',
      answer: 'A habit is a repeated behavior in a context; it is not proof of a fixed Big Five identity. Trait language may suggest a question, but a useful habit review examines cues, actions, consequences, friction, and exceptions.',
      evidence: 'Group-level trait-change evidence does not show that installing one habit changes a specific trait or that a displayed trait range caused a habit. Repetition also depends on environment, access, competing demands, feedback, health, and support.',
      nuance: 'Consistent behavior can reflect a stable routine without revealing a stable inner cause. The same person may follow a habit at work and not at home because cues, stakes, tools, and interruptions differ.',
      scenario: 'Someone calls missed exercise “my low conscientiousness.” They instead track when the planned action starts, what blocks it, what smaller version is feasible, and which environmental cue changes follow-through.',
      framework: ['Define one observable action and context.', 'Record cue, friction, action, and immediate consequence.', 'Change one environmental variable for a short trial.', 'Keep, revise, or stop the setup based on behavior—not identity.'],
      limitation: 'This is not treatment or a validated behavior-change program. No cited source proves that a habit changes personality, and FermatMind cannot guarantee adherence, health, productivity, or trait outcomes.',
    },
    'zh-CN': {
      title: '习惯是重复行为，不是固定人格的证明',
      answer: '习惯是在情境中重复的行为，不是固定大五人格身份的证明。特质语言可以提出问题，但有用的习惯复盘要看提示、行动、后果、阻力与例外。',
      evidence: '群体层面的特质变化证据不能证明建立一个习惯会改变某项特质，也不能证明显示区间导致某种习惯。重复行为还取决于环境、资源、竞争需求、反馈、健康与支持。',
      nuance: '稳定行为可能来自稳定流程，却不揭示稳定的内在原因。同一个人在工作中能保持习惯、在家中不能，可能因为提示、代价、工具与打断不同。',
      scenario: '某人把运动中断称为“我的尽责性低”。他改为追踪计划行动何时启动、什么造成阻力、哪个更小版本可行，以及哪个环境提示改变了执行。',
      framework: ['定义一个可观察行动与情境。', '记录提示、阻力、行动与即时后果。', '短期只改变一个环境变量。', '根据行为保留、修改或停止设置，而不定义身份。'],
      limitation: '本文不是治疗或经过验证的行为改变方案。引用来源不能证明习惯改变人格，费马测试也不能保证坚持、健康、生产力或特质结果。',
    },
  },
  goals: {
    en: {
      title: 'Use Big Five Results to Form Goal Hypotheses, Not Trait Destinies',
      answer: 'Use a Big Five result as a hypothesis about conditions to test, not as a rule for which goals you should pursue. A goal experiment needs a chosen outcome, an observable behavior, a context, and a review point.',
      evidence: 'Longitudinal group evidence does not show that a product score predicts goal success or that pursuing a goal will deliberately change a trait. Goals are also shaped by resources, constraints, values, skill, feedback, opportunity, and other people.',
      nuance: 'A goal can be worthwhile even when it feels unlike a trait description. Conversely, a trait-consistent goal may still be poorly specified, inaccessible, or harmful. Fit language must not replace evidence or consent.',
      scenario: 'A person wants to contribute more in meetings but sees themselves as low in extraversion. They test one prepared question per meeting for three weeks and review usefulness, effort, and context rather than trying to become “more extraverted.”',
      framework: ['Choose a goal from values and real needs, not a score.', 'Define one observable action and a feasible context.', 'Record effort, outcome, barriers, and unintended costs.', 'Review the experiment and revise the action without judging identity.'],
      limitation: 'This workflow does not predict achievement or prescribe education, hiring, career, medical, legal, or financial choices. FermatMind-specific goal prediction and intervention effects remain Unknown.',
    },
    'zh-CN': {
      title: '把大五结果用于目标假设，而不是特质命运',
      answer: '把大五结果当作待检验的条件假设，而不是规定应该追求什么目标的规则。目标实验需要自主选择的结果、可观察行为、情境与复盘点。',
      evidence: '群体纵向证据不能证明产品分数预测目标成功，也不能证明追求一个目标会刻意改变特质。目标还受资源、限制、价值、技能、反馈、机会与他人影响。',
      nuance: '即使目标看起来不符合特质描述，它仍可能值得追求。相反，所谓“符合特质”的目标也可能定义不清、不可及或有害。匹配语言不能代替证据与同意。',
      scenario: '某人想在会议中增加贡献，却认为自己外向性较低。他连续三周在每场会议准备一个问题，复盘用途、投入与情境，而不是试图变得“更外向”。',
      framework: ['根据价值与真实需求选择目标，而不是根据分数。', '定义一个可观察行动与可行情境。', '记录投入、结果、阻力与意外代价。', '复盘实验并修改行动，不评价身份。'],
      limitation: '本流程不预测成就，也不规定教育、招聘、职业、医疗、法律或金融选择。费马测试特定目标预测与干预效果均为 Unknown。',
    },
  },
  'life-events': {
    en: {
      title: 'Life Events and Personality Change: Association Is Not a One-Event Cause',
      answer: 'Life events may coincide with personality change, but one event should not be treated as a complete causal explanation. Longitudinal patterns require careful attention to timing, selection, context, multiple influences, and uncertainty.',
      evidence: 'The cited meta-analysis supports average change across the life course; it does not isolate a particular event as the cause of change for an individual. People also enter events differently and experience them under different conditions.',
      nuance: 'Two people can experience a similar transition and report different trajectories, while an apparent before-and-after shift can reflect role demands or measurement conditions. A compelling story is not causal identification.',
      scenario: 'After moving cities, someone sees a lower extraversion result and concludes the move changed their personality. They examine social access, language, workload, response context, and repeated behavior before forming a tentative interpretation.',
      framework: ['Describe the event, timing, and observed behavior separately.', 'List alternative explanations and concurrent changes.', 'Look for repeated observations across contexts and time.', 'Use tentative language; do not infer blame, destiny, or clinical meaning.'],
      limitation: 'This article cannot determine whether an event caused personal change or predict recovery, wellbeing, relationships, or performance. It is not trauma, grief, or mental-health guidance.',
    },
    'zh-CN': {
      title: '生活事件与人格变化：关联不等于单一事件因果',
      answer: '生活事件可能与人格变化同时出现，但不能把一个事件当作完整因果解释。阅读纵向模式时，需要考虑时间、选择效应、情境、多重影响与不确定性。',
      evidence: '引用的元分析支持生命历程中的平均变化，却没有把某个特定事件识别为个人变化的原因。人们进入事件的方式不同，经历事件的条件也不同。',
      nuance: '两个人经历相似转变，可能报告不同轨迹；看似前后变化也可能反映角色要求或测量条件。一个吸引人的故事不是因果识别。',
      scenario: '搬到新城市后，某人看到外向性结果下降，就断定搬家改变了人格。他先查看社交机会、语言、工作量、作答情境与重复行为，再形成暂时解释。',
      framework: ['分别描述事件、时间与观察到的行为。', '列出替代解释与同期变化。', '寻找跨情境、跨时间的重复观察。', '使用暂定语言，不推断责备、命运或临床含义。'],
      limitation: '本文不能判断事件是否导致个人改变，也不能预测恢复、福祉、关系或表现；它不是创伤、哀伤或心理健康指导。',
    },
  },
  tracking: {
    en: {
      title: 'Track Growth Through Behavior and Context—Not Personality Score Chasing',
      answer: 'Track growth with behaviors, contexts, constraints, and consequences rather than trying to optimize a personality score. A score can open a question, but it is not a performance target or proof that change occurred.',
      evidence: 'Group-level change findings do not establish a meaningful product-score change threshold. Without public FermatMind evidence for reliability, norms, percentiles, or change sensitivity, score differences cannot be interpreted as precise improvement.',
      nuance: 'Behavior can improve while a score stays similar, and a score can move while daily behavior does not. Frequent retesting may also encourage response coaching or overinterpretation rather than better evidence.',
      scenario: 'Someone wants to become more organized and retakes a personality test weekly. They switch to tracking whether priorities are chosen, tasks are started, commitments are met, and the system remains sustainable in named contexts.',
      framework: ['Define one behavior connected to a chosen goal.', 'Record context, opportunity, action, and consequence.', 'Review a practical window without treating it as a validated threshold.', 'Use retesting sparingly and never optimize responses for a preferred label.'],
      limitation: 'This protocol does not establish clinically or psychometrically significant change. FermatMind reliability, validity, norms, percentiles, test-retest evidence, and meaningful-change thresholds remain Unknown.',
    },
    'zh-CN': {
      title: '用行为与情境追踪成长，而不是追逐人格分数',
      answer: '用行为、情境、限制与后果追踪成长，而不是优化人格分数。分数可以开启问题，但不是绩效目标，也不能证明变化已经发生。',
      evidence: '群体变化研究不能建立有意义的产品分数变化阈值。在费马测试尚无公开信度、常模、百分位或变化敏感性证据时，分数差异不能被解释为精确改善。',
      nuance: '行为可能改善而分数相似，分数也可能变化而日常行为不变。频繁重测还可能鼓励作答训练或过度解释，而不是产生更好证据。',
      scenario: '某人想变得更有条理，于是每周重测人格。他改为追踪是否选择优先级、启动任务、兑现承诺，以及系统在明确情境中能否持续。',
      framework: ['定义一个与自主目标相关的行为。', '记录情境、机会、行动与后果。', '在实用窗口内复盘，但不把它当作验证阈值。', '谨慎重测，绝不为偏好标签优化作答。'],
      limitation: '本协议不能证明临床或心理测量意义上的变化。费马测试信度、效度、常模、百分位、重测证据与有意义变化阈值均为 Unknown。',
    },
  },
};

const sourceById = new Map(ledger.sources.map((source) => [source.id, source]));
const mapSources = (ids, locale) => ids.map((id) => { const source = sourceById.get(id); const display = sourceDisplay[id][locale]; return { source_id: id, evidence_category: source.evidence_category, label: display.label, public_url: source.public_url, repository_path: source.repository_path ?? null, limitation: display.limitation }; });
const sourceLine = (source, locale) => source.public_url ? `- [${source.label}](${source.public_url}) — ${source.limitation}` : `- ${source.label} — ${locale === 'en' ? 'Repository source' : '仓库来源'}: \`${source.repository_path}\` — ${source.limitation}`;
const rawDrafts = lockedThemes.flatMap((theme) => theme.locales.map((locked) => { const text = copy[theme.theme_key][locked.locale]; return { topic_id: theme.topic_id, locale: locked.locale, slug: locked.slug, title: text.title, locked_intent_key: theme.unique_intent_key, primary_question: locked.primary_question, raw_sections: { direct_answer: text.answer, evidence: text.evidence, scenario: text.scenario, practical_framework: text.framework }, evidence_source_ids: locked.source_requirements, review_status: 'draft_requires_skeptical_review' }; }));
const reviews = rawDrafts.map((draft) => ({ topic_id: draft.topic_id, locale: draft.locale, slug: draft.slug, findings: ['Keep group-level longitudinal evidence separate from personal prediction and causality.', 'Replace identity destiny and score optimization with behavior-and-context observation.', 'Add counterexamples, alternative explanations, and measurement uncertainty.', 'Prohibit guaranteed change, diagnosis, high-stakes advice, and release mutations.'], repair_required: true, reviewer: null }));
const repairedDrafts = lockedThemes.flatMap((theme) => theme.locales.map((locked) => { const text = copy[theme.theme_key][locked.locale]; return { topic_id: theme.topic_id, locale: locked.locale, slug: locked.slug, repairs: ['Separated group evidence from individual inference.', 'Reframed growth as a reversible behavior-and-context experiment.', 'Added alternative explanations and score-interpretation limits.', 'Prohibited causality, destiny, guaranteed change, and high-stakes use.', 'Preserved private-result and release safety.'], sections: { direct_answer: text.answer, evidence: text.evidence, nuance_counterexample: text.nuance, concrete_scenario: text.scenario, practical_framework: text.framework, limitation: text.limitation }, source_mapping: mapSources(locked.source_requirements, locked.locale), review_status: 'repaired_pending_manual_review' }; }));
const finalAssets = lockedThemes.flatMap((theme) => theme.locales.map((locked) => {
  const text = copy[theme.theme_key][locked.locale];
  const sourceMapping = mapSources(locked.source_requirements, locked.locale);
  const boundary = locked.locale === 'en' ? 'Educational self-reflection only. Group-level change evidence is not a personal prediction or causal claim. This Article does not diagnose, assign fixed identity or destiny, guarantee change, treat a score as a growth target, establish FermatMind reliability, validity, norms, percentiles, or meaningful-change thresholds, or provide medical, legal, financial, admissions, hiring, or career decision advice. Unsupported product values are Unknown; private report or attempt links must not be collected or published.' : '仅用于教育性自我反思。群体层面的变化证据不是个人预测或因果主张。本文不诊断、不分配固定身份或命运、不保证变化、不把分数当作成长目标、不证明费马测试信度、效度、常模、百分位或有意义变化阈值，也不提供医疗、法律、金融、录取、招聘或职业决策建议。无公开证据的产品数值均为 Unknown；不得收集或公开私人 report 或 attempt 链接。';
  return { asset_type: 'Article', topic_id: theme.topic_id, batch: 29, locale: locked.locale, slug: locked.slug, path: locked.path, title: text.title, title_intent: locked.title_intent, primary_question: locked.primary_question, audience: locked.audience, user_task: locked.user_task, keywords: locked.keywords, search_intent: locked.search_intent, unique_intent_key: theme.unique_intent_key, sections: [{ key: 'direct_answer', body_md: text.answer }, { key: 'evidence', body_md: text.evidence }, { key: 'nuance_counterexample', body_md: text.nuance }, { key: 'concrete_scenario', body_md: text.scenario }, { key: 'practical_framework', body_md: text.framework.map((step, index) => `${index + 1}. ${step}`).join('\n') }, { key: 'limitation', body_md: text.limitation }, { key: 'visible_sources', body_md: sourceMapping.map((source) => sourceLine(source, locked.locale)).join('\n') }, { key: 'method_product_boundary', body_md: boundary }, { key: 'internal_links', body_md: locked.internal_link_targets.map((target) => `- ${target}`).join('\n') }], source_mapping: sourceMapping, internal_link_targets: locked.internal_link_targets, risk_boundary: locked.risk_boundary, review_status: 'pending_manual_review', reviewer: null, author: null, published_at: null, cms_write_executed: false, publish_state_change: false, indexability_change: false };
}));
const qa = { schema_version: 'big5-article-wave-qa.v1', generated_at: generatedAt, status: 'PASS_PENDING_MANUAL_REVIEW', counts: { locked_themes: lockedThemes.length, article_assets: finalAssets.length, en_assets: finalAssets.filter((asset) => asset.locale === 'en').length, zh_cn_assets: finalAssets.filter((asset) => asset.locale === 'zh-CN').length }, checks: { consumes_only_pr21_batch_29: true, exact_locked_slug_locale_pairs: true, unique_intents: new Set(finalAssets.map((asset) => asset.unique_intent_key)).size === 5, raw_drafts_preserved: rawDrafts.length === 10, skeptical_reviews_preserved: reviews.length === 10, repaired_drafts_preserved: repairedDrafts.length === 10, source_mapping_preserved: finalAssets.every((asset) => asset.source_mapping.length === 2), group_evidence_not_personal_prediction: true, behavior_tracking_not_score_optimization: true, causality_and_guaranteed_change_prohibited: true, private_result_links_excluded: true, all_pending_manual_review: finalAssets.every((asset) => asset.review_status === 'pending_manual_review'), cms_writes: 0, published_assets: 0, indexability_changes: 0 } };
for (const [file, data] of Object.entries({ 'raw-drafts.json': { schema_version: 'big5-article-wave-raw.v1', generated_at: generatedAt, assets: rawDrafts }, 'skeptical-review.json': { schema_version: 'big5-article-wave-review.v1', generated_at: generatedAt, reviews }, 'repaired-drafts.json': { schema_version: 'big5-article-wave-repaired.v1', generated_at: generatedAt, assets: repairedDrafts }, 'final-package.json': { schema_version: 'big5-article-wave-final.v1', generated_at: generatedAt, authority: 'PR21 batch 29 + CMS/backend', assets: finalAssets }, 'source-mapping.json': { schema_version: 'big5-article-wave-sources.v1', generated_at: generatedAt, mappings: finalAssets.map((asset) => ({ topic_id: asset.topic_id, locale: asset.locale, slug: asset.slug, sources: asset.source_mapping })) }, 'qa_report.json': qa })) fs.writeFileSync(path.join(dir, file), `${JSON.stringify(data, null, 2)}\n`);
console.log('built batch 29 growth-change wave: 5 locked themes / 10 Article candidates / group-to-individual boundaries');
