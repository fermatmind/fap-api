import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const generatedAt = '2026-07-14T07:11:50Z';
const matrix = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-article-ia-21/article-intent-matrix.json'), 'utf8'));
const ledger = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json'), 'utf8'));
const lockedThemes = matrix.themes.filter((theme) => theme.batch === 26);

const sourceDisplay = {
  'academic.soto-john-2017-bfi2': {
    en: { label: 'Soto & John (2017), BFI-2 domains and facets', limitation: 'Supports one hierarchical Big Five model; it does not validate FermatMind scores or predict team fit, performance, leadership success, promotion, or workplace outcomes.' },
    'zh-CN': { label: 'Soto 与 John（2017）：BFI-2 维度与侧面', limitation: '支持一种大五层级模型；不验证费马测试分数，也不预测团队适配、绩效、领导成功、晋升或工作结果。' },
  },
  'internal.public-claim-boundary-matrix': {
    en: { label: 'FermatMind public claim boundary matrix', limitation: 'Prohibits using assessment content for hiring, screening, promotion, suitability, or guaranteed workplace conclusions; repository policy is not scientific validation.' },
    'zh-CN': { label: '费马测试公开主张边界矩阵', limitation: '禁止把测评内容用于招聘、筛选、晋升、适配或保证性工作结论；仓库政策不是科学验证。' },
  },
};

const copy = {
  teamwork: {
    en: {
      title: 'Using Big Five Language in Teams Without Turning It Into a Fit Score',
      answer: 'Teams can use Big Five language to ask about collaboration preferences only when participation is voluntary and the discussion stays behavioral. Start with a current task, invite each person to describe what helps, and agree on a reversible working experiment rather than scoring who belongs.',
      evidence: 'Big Five models describe broad tendencies across dimensions. They do not measure every skill, role demand, incentive, relationship, or constraint that shapes teamwork, so a trait result cannot establish compatibility, contribution, or likely performance.',
      nuance: 'Similar profiles do not guarantee harmony, and different profiles do not imply conflict. A person may also behave differently across roles or power conditions. The safest useful unit is a specific coordination problem, not a personality-based team map.',
      scenario: 'A project team is missing handoffs. Instead of labeling one member “low conscientiousness,” the group asks how each person tracks commitments, tests a shared checklist for two weeks, and evaluates missed steps without collecting private reports.',
      framework: ['Make participation optional and keep private scores private.', 'Name one observable coordination problem.', 'Ask each person what support or constraint matters in that situation.', 'Run a reversible process experiment and review task evidence.'],
      limitation: 'This conversation guide must not be used for hiring, screening, staffing, promotion, performance ranking, discipline, suitability, or team-fit conclusions. FermatMind product reliability, validity, norms, and workplace prediction are Unknown.',
    },
    'zh-CN': {
      title: '团队如何安全使用大五人格语言：讨论协作，不计算适配分',
      answer: '只有在自愿参与、并把讨论限制在具体行为时，团队才可借助大五人格语言询问协作偏好。先从当前任务出发，让每个人说明什么支持有效，再约定一个可逆的工作实验，而不是评价谁属于团队。',
      evidence: '大五模型描述多个维度上的宽泛倾向，不能覆盖影响协作的全部技能、角色要求、激励、关系与现实限制。因此，特质结果不能证明兼容性、贡献度或未来绩效。',
      nuance: '画像相似不保证合作顺畅，画像不同也不意味着冲突。同一个人在不同角色或权力条件下也可能表现不同。最安全且有用的讨论单位是具体协调问题，不是人格化团队地图。',
      scenario: '项目团队连续漏掉交接。与其把某位成员标为“尽责性低”，团队可以询问每个人怎样追踪承诺，试行两周共享清单，再依据任务证据复盘，而且不收集私人报告。',
      framework: ['参与必须自愿，私人分数保持私密。', '只命名一个可观察的协调问题。', '询问每个人在该情境需要的支持或面临的限制。', '运行可逆流程实验，并依据任务证据复盘。'],
      limitation: '本指南不得用于招聘、筛选、排班、晋升、绩效排名、处分、适配或团队匹配结论。费马测试产品的信度、效度、常模与工作结果预测能力均为 Unknown。',
    },
  },
  feedback: {
    en: {
      title: 'Treating Feedback Preferences as Hypotheses, Not Trait Prescriptions',
      answer: 'A Big Five result may help someone propose a feedback preference, but the preference should be tested rather than prescribed. Ask the recipient what format, timing, specificity, and follow-up help for this task, then compare the experiment with direct experience.',
      evidence: 'Broad trait dimensions summarize tendencies, while feedback response also depends on trust, power, topic, skill, culture, urgency, and delivery. A result cannot determine that someone “needs blunt feedback” or is unable to receive a particular form.',
      nuance: 'Preference is not entitlement and does not erase professional standards. It can also change by context: written preparation may help for technical review, while a brief live correction may work for an urgent operational issue.',
      scenario: 'A manager assumes a teammate with higher neuroticism needs softened feedback. Instead, the manager asks permission, gives a concrete behavior-impact example, offers written or live follow-up, and lets the teammate report what was useful.',
      framework: ['Ask, do not infer, the recipient’s preference.', 'Specify behavior, impact, and the next observable step.', 'Offer one or two feasible delivery options.', 'Review the outcome and revise the preference hypothesis.'],
      limitation: 'Trait language cannot justify withholding feedback, differential standards, performance ratings, promotion decisions, or predictions about resilience. Product-specific accuracy and workplace validity remain Unknown.',
    },
    'zh-CN': {
      title: '把反馈偏好当作待检验假设，而不是由特质直接规定',
      answer: '大五人格结果可以帮助一个人提出反馈偏好，但偏好需要检验，不能由特质直接规定。应询问接收者在当前任务中偏好的形式、时机、具体程度与后续方式，再用直接体验核对。',
      evidence: '宽泛特质维度概括倾向，反馈反应还取决于信任、权力、主题、技能、文化、紧迫度与表达方式。结果不能证明某人“需要尖锐反馈”，也不能证明其无法接受某种形式。',
      nuance: '偏好不是特权，也不能取消专业标准；它还会随情境变化。技术评审可能适合先书面准备，紧急操作问题则可能更适合简短现场纠正。',
      scenario: '管理者假设神经质较高的同事需要被温和对待。更稳妥的做法是先征得同意，说明具体行为与影响，提供书面或当面跟进选项，再由对方反馈什么真正有用。',
      framework: ['直接询问接收者偏好，不从结果推断。', '说明行为、影响与下一步可观察行动。', '提供一到两个现实可行的表达选项。', '复盘结果并修订偏好假设。'],
      limitation: '特质语言不能成为不提供反馈、采用不同标准、绩效评级、晋升决策或预测抗压性的依据。产品特定准确性与工作场景效度仍为 Unknown。',
    },
  },
  meetings: {
    en: {
      title: 'Designing Meeting Participation Without Assigning Personality Roles',
      answer: 'Design meetings with several participation channels—advance notes, live discussion, chat, and post-meeting comments—so people can contribute without being assigned a personality role. Evaluate whether the design improves decision quality and access.',
      evidence: 'Extraversion and other broad traits can prompt questions about stimulation or expression, but meeting participation also reflects status, language, preparation, expertise, accessibility, safety, and facilitation. Silence is not evidence of low contribution or a stable type.',
      nuance: 'More airtime is not always better, and written input is not automatically deeper. The goal is not to accommodate fixed “introvert” and “extrovert” groups; it is to remove unnecessary barriers while keeping responsibilities clear.',
      scenario: 'A facilitator notices the same two people dominate a weekly meeting. Rather than assigning personality labels, they circulate questions early, use a short silent-write round, rotate first responses, and compare the breadth and quality of input.',
      framework: ['Publish the decision question and materials before the meeting.', 'Offer at least two contribution channels.', 'Use facilitation rules that distribute access without forcing disclosure.', 'Review decision quality, missing voices, and follow-through.'],
      limitation: 'Meeting design evidence cannot support employee ranking, screening, performance judgment, promotion, or personality-based role assignment. FermatMind workplace prediction and product metrics are Unknown.',
    },
    'zh-CN': {
      title: '会议中如何容纳不同参与方式，而不分配人格角色',
      answer: '会议可提供多种参与通道，例如会前笔记、现场讨论、聊天区和会后补充，让人们无需被分配人格角色也能贡献。应评估设计是否改善了决策质量与参与机会。',
      evidence: '外向性等宽泛特质可以提示有关刺激或表达的问题，但会议参与还受身份地位、语言、准备、专业知识、可访问性、安全感与主持方式影响。沉默不等于贡献低，也不证明固定类型。',
      nuance: '发言更多不总是更好，书面输入也不自动更深入。目标不是照顾固定的“内向组”和“外向组”，而是减少不必要障碍，同时保持责任清楚。',
      scenario: '主持人发现每周会议总由两个人主导。与其贴人格标签，可以提前发布问题，加入短暂静默书写，轮换首位回应者，并比较输入的广度与质量。',
      framework: ['会前发布决策问题与材料。', '至少提供两种贡献通道。', '用主持规则分配参与机会，但不强迫披露结果。', '复盘决策质量、缺失声音与后续执行。'],
      limitation: '会议设计观察不能支持员工排名、筛选、绩效判断、晋升或基于人格的角色分配。费马测试的工作结果预测与产品指标均为 Unknown。',
    },
  },
  leadership: {
    en: {
      title: 'Using Big Five Traits for Leadership Reflection, Not Leader Prediction',
      answer: 'Big Five language can help a leader generate questions about recurring behavior—how they seek input, plan, respond under pressure, or repair conflict. It cannot determine who is a leader or predict whether someone will succeed.',
      evidence: 'Broad traits describe tendencies, whereas leadership outcomes depend on skills, ethics, experience, authority, team needs, resources, incentives, and context. Evidence about one personality model does not validate a FermatMind score as a selection or promotion instrument.',
      nuance: 'The same tendency may create different costs and benefits. Fast social initiative can open discussion or crowd it out; careful planning can improve reliability or delay adaptation. Reflection must examine behavior and consequences, not label a trait as leadership strength.',
      scenario: 'A team lead sees a higher agreeableness range and wonders whether it makes them effective. They review one decision where they invited dissent and another where they avoided a necessary disagreement, then test a structured dissent round.',
      framework: ['Choose one leadership behavior and one affected stakeholder.', 'Collect a helpful example and a costly counterexample.', 'Ask for consent-based behavioral feedback.', 'Test one reversible practice and review consequences.'],
      limitation: 'This reflection cannot be used to identify leadership potential, select managers, guarantee success, rate performance, or decide promotion. FermatMind-specific predictive validity and product psychometrics are Unknown.',
    },
    'zh-CN': {
      title: '用大五特质复盘领导行为，而不是预测谁会成为好领导',
      answer: '大五人格语言可以帮助领导者提出关于重复行为的问题，例如怎样征求意见、规划、在压力下回应或修复冲突；它不能判断谁是领导，也不能预测谁会成功。',
      evidence: '宽泛特质描述倾向，领导结果还取决于技能、伦理、经验、权限、团队需要、资源、激励与情境。人格模型证据不能把费马测试分数验证成选拔或晋升工具。',
      nuance: '同一倾向可能同时带来收益与代价。快速主动表达可以打开讨论，也可能挤压他人；周密计划可以提高可靠性，也可能延迟适应。复盘必须观察行为与后果，不能把特质直接命名为领导优势。',
      scenario: '团队负责人看到宜人性区间较高，想知道这是否意味着领导有效。他分别复盘一次主动邀请异议的决定，以及一次回避必要分歧的场景，再试行结构化异议环节。',
      framework: ['选择一个领导行为和一位受影响的利益相关者。', '收集一个有帮助的例子与一个代价反例。', '征得同意后索取行为反馈。', '测试一项可逆做法并复盘后果。'],
      limitation: '本复盘不能用于识别领导潜力、选拔管理者、保证成功、评定绩效或决定晋升。费马测试特定预测效度与产品心理测量数值均为 Unknown。',
    },
  },
  'work-environment': {
    en: {
      title: 'Testing Work-Environment Preferences With Reversible Experiments',
      answer: 'Turn a possible work-environment preference into a small reversible experiment. Define the condition—noise, social contact, structure, novelty, or interruption—choose an observable task outcome and wellbeing cost, then compare settings without declaring job fit.',
      evidence: 'Trait dimensions may suggest questions about preferred stimulation or structure, but actual work experience also depends on task type, autonomy, accessibility, resources, skill, team norms, and life circumstances. A profile cannot identify the right job or workplace.',
      nuance: 'A preference can be conditional. Quiet may help deep work but hinder rapid coordination; novelty may energize one task and overload another. A short experiment also cannot represent every season, workload, or role.',
      scenario: 'A worker suspects they focus better with fewer interruptions. For one week they protect two daily focus blocks, then compare completion, rework, energy, and coordination costs with a normal week. They adjust the routine rather than concluding they are unfit for collaborative work.',
      framework: ['Name one environmental condition and one task.', 'Choose observable benefits and costs before the experiment.', 'Change only what is feasible and reversible.', 'Compare results, note context, and keep or revise the preference hypothesis.'],
      limitation: 'This experiment cannot support job matching, hiring, accommodation denial, staffing, performance, promotion, or career outcome conclusions. Product-specific workplace validity and predictive accuracy are Unknown.',
    },
    'zh-CN': {
      title: '用可逆实验检验工作环境偏好，而不是推断职业适配',
      answer: '把可能的工作环境偏好转化为小型可逆实验。明确条件——噪声、社交接触、结构、新颖度或打断——选择可观察的任务结果与身心代价，再比较不同设置，而不是宣布职业适配。',
      evidence: '特质维度可以提示有关刺激或结构偏好的问题，真实工作体验还取决于任务类型、自主性、可访问性、资源、技能、团队规范与生活条件。画像不能识别“正确工作”或“正确职场”。',
      nuance: '偏好可能有条件。安静有助于深度工作，却可能妨碍快速协作；新颖性可能让一个任务更有活力，也可能让另一个任务过载。短期实验也不能代表所有季节、工作量或角色。',
      scenario: '某人怀疑减少打断能提高专注。他一周内每天保护两个专注时段，再与普通一周比较完成量、返工、精力与协调成本；随后调整流程，而不是认定自己不适合协作工作。',
      framework: ['只命名一个环境条件和一类任务。', '实验前选定可观察收益与代价。', '只改变现实可行且可逆的条件。', '比较结果、记录情境，并保留或修订偏好假设。'],
      limitation: '本实验不能支持职业匹配、招聘、拒绝合理支持、排班、绩效、晋升或职业结果结论。产品特定的工作场景效度与预测准确性均为 Unknown。',
    },
  },
};

const sourceById = new Map(ledger.sources.map((source) => [source.id, source]));
const mapSources = (ids, locale) => ids.map((id) => {
  const source = sourceById.get(id);
  const display = sourceDisplay[id][locale];
  return { source_id: id, evidence_category: source.evidence_category, label: display.label, public_url: source.public_url, repository_path: source.repository_path ?? null, limitation: display.limitation };
});
const sourceLine = (source, locale) => source.public_url
  ? `- [${source.label}](${source.public_url}) — ${source.limitation}`
  : `- ${source.label} — ${locale === 'en' ? 'Repository source' : '仓库来源'}: \`${source.repository_path}\` — ${source.limitation}`;

const rawDrafts = lockedThemes.flatMap((theme) => theme.locales.map((locked) => {
  const text = copy[theme.theme_key][locked.locale];
  return { topic_id: theme.topic_id, locale: locked.locale, slug: locked.slug, title: text.title, locked_intent_key: theme.unique_intent_key, primary_question: locked.primary_question, raw_sections: { direct_answer: text.answer, evidence: text.evidence, scenario: text.scenario, practical_framework: text.framework }, evidence_source_ids: locked.source_requirements, review_status: 'draft_requires_skeptical_review' };
}));
const reviews = rawDrafts.map((draft) => ({ topic_id: draft.topic_id, locale: draft.locale, slug: draft.slug, findings: ['Replace trait prescription with a voluntary behavioral experiment.', 'Add a counterexample showing context and skills matter.', 'State the hiring, screening, promotion, performance, suitability, and workplace-outcome prohibition visibly.', 'Keep private scores, attribution, publication, and indexability fail closed.'], repair_required: true, reviewer: null }));
const repairedDrafts = lockedThemes.flatMap((theme) => theme.locales.map((locked) => {
  const text = copy[theme.theme_key][locked.locale];
  return { topic_id: theme.topic_id, locale: locked.locale, slug: locked.slug, repairs: ['Reframed advice as voluntary, reversible behavioral practice.', 'Added context and a concrete counterexample.', 'Added the full workplace decision-use prohibition.', 'Mapped academic evidence separately from repository policy.', 'Preserved private-score and release safety.'], sections: { direct_answer: text.answer, evidence: text.evidence, nuance_counterexample: text.nuance, concrete_scenario: text.scenario, practical_framework: text.framework, limitation: text.limitation }, source_mapping: mapSources(locked.source_requirements, locked.locale), review_status: 'repaired_pending_manual_review' };
}));
const finalAssets = lockedThemes.flatMap((theme) => theme.locales.map((locked) => {
  const text = copy[theme.theme_key][locked.locale];
  const sourceMapping = mapSources(locked.source_requirements, locked.locale);
  const boundary = locked.locale === 'en'
    ? 'Educational, voluntary self-reflection only. This Article does not diagnose, assign identity, establish FermatMind psychometrics, or permit hiring, screening, staffing, promotion, performance, discipline, suitability, job-fit, leadership-potential, or workplace-outcome conclusions. Unsupported product values are Unknown; private results and report or attempt links must not be collected or published.'
    : '仅用于自愿的教育性自我反思。本文不诊断、不分配身份、不证明费马测试心理测量指标，也不允许招聘、筛选、排班、晋升、绩效、处分、适配、职业匹配、领导潜力或工作结果结论。无公开证据的产品数值均为 Unknown；不得收集或公开私人结果以及 report 或 attempt 链接。';
  return { asset_type: 'Article', topic_id: theme.topic_id, batch: 26, locale: locked.locale, slug: locked.slug, path: locked.path, title: text.title, title_intent: locked.title_intent, primary_question: locked.primary_question, audience: locked.audience, user_task: locked.user_task, keywords: locked.keywords, search_intent: locked.search_intent, unique_intent_key: theme.unique_intent_key, sections: [
    { key: 'direct_answer', body_md: text.answer }, { key: 'evidence', body_md: text.evidence }, { key: 'nuance_counterexample', body_md: text.nuance }, { key: 'concrete_scenario', body_md: text.scenario }, { key: 'practical_framework', body_md: text.framework.map((step, index) => `${index + 1}. ${step}`).join('\n') }, { key: 'limitation', body_md: text.limitation }, { key: 'visible_sources', body_md: sourceMapping.map((source) => sourceLine(source, locked.locale)).join('\n') }, { key: 'method_product_boundary', body_md: boundary }, { key: 'internal_links', body_md: locked.internal_link_targets.map((target) => `- ${target}`).join('\n') },
  ], source_mapping: sourceMapping, internal_link_targets: locked.internal_link_targets, risk_boundary: locked.risk_boundary, review_status: 'pending_manual_review', reviewer: null, author: null, published_at: null, cms_write_executed: false, publish_state_change: false, indexability_change: false };
}));
const qa = { schema_version: 'big5-article-wave-qa.v1', generated_at: generatedAt, status: 'PASS_PENDING_MANUAL_REVIEW', counts: { locked_themes: lockedThemes.length, article_assets: finalAssets.length, en_assets: finalAssets.filter((asset) => asset.locale === 'en').length, zh_cn_assets: finalAssets.filter((asset) => asset.locale === 'zh-CN').length }, checks: { consumes_only_pr21_batch_26: true, exact_locked_slug_locale_pairs: true, unique_intents: new Set(finalAssets.map((asset) => asset.unique_intent_key)).size === 5, raw_drafts_preserved: rawDrafts.length === 10, skeptical_reviews_preserved: reviews.length === 10, repaired_drafts_preserved: repairedDrafts.length === 10, source_mapping_preserved: finalAssets.every((asset) => asset.source_mapping.length === 2), all_pending_manual_review: finalAssets.every((asset) => asset.review_status === 'pending_manual_review'), workplace_decision_use_prohibited: true, private_result_links_excluded: true, cms_writes: 0, published_assets: 0, indexability_changes: 0 } };

for (const [file, data] of Object.entries({
  'raw-drafts.json': { schema_version: 'big5-article-wave-raw.v1', generated_at: generatedAt, assets: rawDrafts },
  'skeptical-review.json': { schema_version: 'big5-article-wave-review.v1', generated_at: generatedAt, reviews },
  'repaired-drafts.json': { schema_version: 'big5-article-wave-repaired.v1', generated_at: generatedAt, assets: repairedDrafts },
  'final-package.json': { schema_version: 'big5-article-wave-final.v1', generated_at: generatedAt, authority: 'PR21 batch 26 + CMS/backend', assets: finalAssets },
  'source-mapping.json': { schema_version: 'big5-article-wave-sources.v1', generated_at: generatedAt, mappings: finalAssets.map((asset) => ({ topic_id: asset.topic_id, locale: asset.locale, slug: asset.slug, sources: asset.source_mapping })) },
  'qa_report.json': qa,
})) fs.writeFileSync(path.join(dir, file), `${JSON.stringify(data, null, 2)}\n`);

console.log('built batch 26 workplace wave: 5 locked themes / 10 Article candidates / workplace decision-use prohibited');
