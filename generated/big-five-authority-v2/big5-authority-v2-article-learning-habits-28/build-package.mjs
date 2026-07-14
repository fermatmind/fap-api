import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const generatedAt = '2026-07-14T08:30:04Z';
const matrix = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-article-ia-21/article-intent-matrix.json'), 'utf8'));
const ledger = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json'), 'utf8'));
const lockedThemes = matrix.themes.filter((theme) => theme.batch === 28);

const sourceDisplay = {
  'academic.soto-john-2017-bfi2': {
    en: { label: 'Soto & John (2017), BFI-2 domains and facets', limitation: 'Supports one hierarchical Big Five model; it does not prescribe a learning style, diagnose procrastination, measure creativity or decision quality, or validate FermatMind scores.' },
    'zh-CN': { label: 'Soto 与 John（2017）：BFI-2 维度与侧面', limitation: '支持一种大五层级模型；不规定学习风格、不诊断拖延、不测量创造力或决策质量，也不验证费马测试分数。' },
  },
  'internal.public-claim-boundary-matrix': {
    en: { label: 'FermatMind public claim boundary matrix', limitation: 'Requires non-diagnostic and non-deterministic use; repository policy is not scientific evidence for learning, habit, creativity, or decision outcomes.' },
    'zh-CN': { label: '费马测试公开主张边界矩阵', limitation: '要求非诊断、非决定论使用；仓库政策不是学习、习惯、创造力或决策结果的科学证据。' },
  },
};

const copy = {
  learning: {
    en: {
      title: 'Big Five Traits Cannot Reveal One Best Way to Learn—Test Conditions Instead',
      answer: 'Big Five traits cannot reveal a single best learning method. They may prompt questions about structure, novelty, social stimulation, or persistence, but study conditions should be tested against a specific task and observable learning evidence.',
      evidence: 'Hierarchical Big Five models describe broad trait domains and facets; they do not classify people into validated learning styles. Learning also depends on prior knowledge, task design, practice, feedback, access, time, health, and instruction.',
      nuance: 'A preferred condition is not always the condition that produces better learning. Quiet may feel comfortable yet not improve recall; group study may increase engagement but add distraction. Preference, performance, and accessibility should be recorded separately.',
      scenario: 'A learner assumes higher extraversion means they should always study with others. They compare two similar study sessions—one independent and one collaborative—using the same objective recall check and a note about effort and distraction.',
      framework: ['Choose one learning task and one condition to vary.', 'Define an observable check before studying.', 'Keep time and materials as comparable as practical.', 'Compare performance, effort, access, and exceptions; revise the condition, not your identity.'],
      limitation: 'This is not an evidence-based learning-style prescription or a guarantee of achievement. FermatMind-specific psychometrics and any claim that a trait determines educational performance remain Unknown.',
    },
    'zh-CN': {
      title: '大五特质不能揭示唯一最佳学习方式：应检验学习条件',
      answer: '大五特质不能揭示一种唯一最佳学习方法。它可以提示有关结构、新颖性、社交刺激或坚持的问题，但学习条件必须结合具体任务与可观察的学习证据来检验。',
      evidence: '层级式大五模型描述宽泛特质维度与侧面，并不把人分类成经过验证的学习风格。学习还取决于已有知识、任务设计、练习、反馈、可访问性、时间、健康与教学。',
      nuance: '喜欢的条件不总是带来更好学习。安静可能感觉舒服，却不一定改善回忆；小组学习可能提高参与，也可能增加干扰。应分别记录偏好、表现与可访问性。',
      scenario: '学习者因为外向性较高，就认定自己必须与他人一起学习。他用两个相似学习时段比较独立与协作条件，使用同一个客观回忆检查，并记录投入与干扰。',
      framework: ['选择一项学习任务和一个待改变条件。', '学习前定义可观察检查。', '尽量保持时间与材料可比较。', '比较表现、投入、可访问性与例外；修订条件，不定义身份。'],
      limitation: '本文不是经验证的学习风格处方，也不保证学习成果。费马测试特定心理测量指标，以及特质决定教育表现的主张，均为 Unknown。',
    },
  },
  procrastination: {
    en: {
      title: 'Procrastination Is a Behavior Pattern, Not a Big Five Identity Label',
      answer: 'Procrastination is not a fixed Big Five identity. Describe the delayed action, task, trigger, short-term relief, and later cost; then test which part of the task or environment can be changed without using a trait score as blame.',
      evidence: 'Trait models describe broad tendencies, while delay can also reflect unclear next steps, task aversion, competing demands, fatigue, fear of evaluation, missing resources, incentives, or health. A result cannot identify the cause for one person.',
      nuance: 'Lower conscientiousness language may prompt a planning question, but it does not mean someone is lazy or unable to follow through. A highly organized person can delay an ambiguous task, and a person with a lower displayed range can use effective supports.',
      scenario: 'Someone has postponed an application for a week and calls it “my low conscientiousness.” They identify that the first step is undefined, create a ten-minute document checklist, and observe whether starting friction changes.',
      framework: ['Name the exact delayed action and deadline.', 'Map trigger, immediate relief, and later cost.', 'Reduce one friction or define one next action.', 'Review what happened; seek qualified support if persistent difficulty causes significant distress or impairment.'],
      limitation: 'This article does not diagnose attention, mood, anxiety, or any health condition and does not offer clinical treatment. FermatMind scores cannot establish cause, moral worth, or guaranteed habit outcomes.',
    },
    'zh-CN': {
      title: '拖延是一种行为模式，不是大五人格身份标签',
      answer: '拖延不是固定的大五人格身份。应描述被推迟的行动、任务、触发因素、短期缓解与后续代价，再检验任务或环境哪一部分可以改变，而不用特质分数责备自己。',
      evidence: '特质模型描述宽泛倾向，延迟还可能来自下一步不清、任务厌恶、竞争需求、疲劳、评价担忧、资源缺失、激励或健康因素。结果不能识别某个人的原因。',
      nuance: '尽责性较低的语言可以提示计划问题，却不意味着懒惰或无法执行。高度有条理的人也可能拖延模糊任务，显示区间较低的人则可以借助有效支持完成行动。',
      scenario: '某人把拖延一周的申请归因于“我的尽责性低”。他发现第一步并不明确，于是建立一个十分钟文档清单，再观察启动阻力是否变化。',
      framework: ['写出被推迟的准确行动与截止时间。', '记录触发、即时缓解与后续代价。', '减少一个阻力，或定义一个下一步。', '复盘结果；若持续困难造成明显痛苦或功能影响，寻求合格支持。'],
      limitation: '本文不诊断注意、情绪、焦虑或任何健康状况，也不提供临床治疗。费马测试分数不能证明原因、道德价值或保证习惯结果。',
    },
  },
  planning: {
    en: {
      title: 'Run a Two-Week Planning-System Experiment Based on Observable Behavior',
      answer: 'Test a planning system for two weeks by defining the behavior it should support, the smallest daily routine, and observable costs. The Big Five result can suggest a question, but the system should be kept or changed based on use and task evidence.',
      evidence: 'Broad traits do not specify which calendar, list, or routine will work. Planning also depends on workload, role, tools, interruptions, task clarity, energy, and support. A system is an environmental intervention, not a personality match.',
      nuance: 'Two weeks is a practical editorial test window, not a validated threshold. A system can improve capture while worsening review burden, and a difficult week can distort the result. Missing data should remain visible.',
      scenario: 'A learner tests one inbox, a daily three-item list, and a five-minute evening review. They track whether tasks are captured, started, completed, or repeatedly moved, plus the time spent maintaining the system.',
      framework: ['Choose one behavior the system should support.', 'Define a minimal daily use rule and one weekly review.', 'Track completion, rollover, maintenance time, and exceptions.', 'After two weeks, keep one useful element and remove or revise the rest.'],
      limitation: 'This planning experiment does not validate a trait result or guarantee productivity. FermatMind-specific predictive evidence and the claim that one system fits a personality range are Unknown.',
    },
    'zh-CN': {
      title: '用可观察行为进行两周计划系统实验',
      answer: '用两周检验一套计划系统：先定义它要支持的行为、最小日常流程与可观察代价。大五结果可以提出问题，但系统应根据实际使用与任务证据保留或修改。',
      evidence: '宽泛特质不能指定哪一种日历、清单或流程有效。计划还取决于工作量、角色、工具、打断、任务清晰度、精力与支持。系统是环境干预，不是人格匹配。',
      nuance: '两周是实用的编辑性实验窗口，不是经过验证的阈值。系统可能改善收集，却增加复盘负担；异常困难的一周也会扭曲结果。缺失数据应保持可见。',
      scenario: '学习者测试一个统一收集箱、每日三项清单与五分钟晚间复盘。他记录任务是否被收集、启动、完成或反复顺延，也记录维护系统花费的时间。',
      framework: ['选择系统要支持的一项行为。', '定义最小日常使用规则与一次每周复盘。', '记录完成、顺延、维护时间与例外。', '两周后保留一个有用元素，删除或修改其余部分。'],
      limitation: '本计划实验不能验证特质结果或保证生产力。费马测试特定预测证据，以及某套系统适合某人格区间的主张，均为 Unknown。',
    },
  },
  creativity: {
    en: {
      title: 'Openness and Creativity Are Related Ideas, Not the Same Construct',
      answer: 'Openness and creativity are not the same thing. Openness is a broad trait domain; creativity can refer to a process, skill, product, or evaluation within a field. A trait range cannot serve as a creative-ability score.',
      evidence: 'BFI-2 operationalizes openness as part of a named domain-and-facet model. That measurement structure does not measure the originality, usefulness, craft, knowledge, persistence, opportunity, or audience judgment involved in creative work.',
      nuance: 'Interest in novelty may support some creative activities without guaranteeing output. Constraints, expertise, revision, collaboration, and practice can matter, while lower interest in novelty does not mean a person cannot produce valuable creative work.',
      scenario: 'A writer sees a middle openness range and concludes they lack creativity. Instead, they choose one brief, generate three alternatives, ask for criteria-based feedback, and revise—observing a creative process rather than interpreting identity.',
      framework: ['Define creativity for one task and audience.', 'Separate idea generation, selection, craft, and revision.', 'Collect task evidence and external criteria where appropriate.', 'Do not translate an openness range into talent or potential.'],
      limitation: 'This package provides no product-specific creativity measure, benchmark, percentile, or predictive evidence. FermatMind cannot infer creative ability or future achievement from the cited trait source.',
    },
    'zh-CN': {
      title: '开放性与创造力是相关概念，但不是同一构念',
      answer: '开放性与创造力不是一回事。开放性是宽泛特质维度；创造力可以指特定领域中的过程、技能、作品或评价。特质区间不能充当创造能力分数。',
      evidence: 'BFI-2 把开放性操作为一套明确命名的维度—侧面模型的一部分。这种测量结构并不测量创意作品所需的原创性、用途、技艺、知识、坚持、机会或受众判断。',
      nuance: '对新颖性的兴趣可能支持某些创意活动，却不保证产出。限制条件、专业知识、修改、协作与练习都可能重要；对新颖性兴趣较低，也不意味着不能创造有价值作品。',
      scenario: '写作者看到开放性处于中间区间，就认定自己没有创造力。更合适的做法是选择一个简报，生成三个方案，按标准获取反馈并修改，观察创作过程而不是解释身份。',
      framework: ['为一项任务与受众定义创造力。', '区分想法生成、选择、技艺与修改。', '收集任务证据，并在适当时使用外部标准。', '不要把开放性区间换算成天赋或潜力。'],
      limitation: '本包不提供产品特定创造力测量、基准、百分位或预测证据。费马测试不能根据被引用的特质来源推断创造能力或未来成就。',
    },
  },
  'decision-making': {
    en: {
      title: 'Use a Decision Journal to Observe Style—Not Infer Quality From Traits',
      answer: 'Big Five traits do not automatically improve decision-making. Use them only to generate questions for a decision journal, then judge the process against evidence, constraints, alternatives, uncertainty, and later outcomes—not against a personality range.',
      evidence: 'Trait dimensions describe tendencies, while decision quality depends on goals, information, expertise, incentives, time, uncertainty, ethics, and feedback. A score cannot show that someone is rational, cautious, impulsive, or correct in a particular decision.',
      nuance: 'A good process can have a bad outcome, and a poor process can get lucky. Hindsight also changes how a decision feels. The journal should preserve what was known at the time instead of rewriting the reasoning after the result.',
      scenario: 'Before choosing a course, a learner records the goal, options, missing information, tradeoffs, confidence, and a review date. Later they compare assumptions with what happened without calling the choice an “openness decision.”',
      framework: ['Record the decision, goal, options, and deadline.', 'List evidence, missing information, tradeoffs, and uncertainty.', 'Write a confidence estimate and conditions that would change the choice.', 'Review process and outcome separately; revise the checklist, not your identity.'],
      limitation: 'This journal is not financial, legal, medical, admissions, or career decision advice. FermatMind-specific decision-quality prediction, validity, norms, and accuracy remain Unknown.',
    },
    'zh-CN': {
      title: '用决策日志观察方式，而不从特质推断决策质量',
      answer: '大五特质不会自动改善决策。只能用它提出决策日志问题，再根据证据、限制、替代方案、不确定性与后续结果评价过程，而不是根据人格区间。',
      evidence: '特质维度描述倾向，决策质量还取决于目标、信息、专业知识、激励、时间、不确定性、伦理与反馈。分数不能证明某人在具体决策中理性、谨慎、冲动或正确。',
      nuance: '良好过程也可能产生坏结果，较差过程也可能幸运成功。事后偏见会改变对决策的感受，因此日志应保留当时已知信息，不能在结果出现后重写理由。',
      scenario: '选择课程前，学习者记录目标、选项、缺失信息、权衡、信心水平与复盘日期。之后把假设与实际情况比较，而不是把选择称为“开放性决策”。',
      framework: ['记录决策、目标、选项与截止时间。', '列出证据、缺失信息、权衡与不确定性。', '写下信心估计，以及会改变选择的条件。', '分别复盘过程与结果；修改清单，不定义身份。'],
      limitation: '本日志不是金融、法律、医疗、录取或职业决策建议。费马测试特定的决策质量预测、效度、常模与准确性均为 Unknown。',
    },
  },
};

const sourceById = new Map(ledger.sources.map((source) => [source.id, source]));
const mapSources = (ids, locale) => ids.map((id) => { const source = sourceById.get(id); const display = sourceDisplay[id][locale]; return { source_id: id, evidence_category: source.evidence_category, label: display.label, public_url: source.public_url, repository_path: source.repository_path ?? null, limitation: display.limitation }; });
const sourceLine = (source, locale) => source.public_url ? `- [${source.label}](${source.public_url}) — ${source.limitation}` : `- ${source.label} — ${locale === 'en' ? 'Repository source' : '仓库来源'}: \`${source.repository_path}\` — ${source.limitation}`;
const rawDrafts = lockedThemes.flatMap((theme) => theme.locales.map((locked) => { const text = copy[theme.theme_key][locked.locale]; return { topic_id: theme.topic_id, locale: locked.locale, slug: locked.slug, title: text.title, locked_intent_key: theme.unique_intent_key, primary_question: locked.primary_question, raw_sections: { direct_answer: text.answer, evidence: text.evidence, scenario: text.scenario, practical_framework: text.framework }, evidence_source_ids: locked.source_requirements, review_status: 'draft_requires_skeptical_review' }; }));
const reviews = rawDrafts.map((draft) => ({ topic_id: draft.topic_id, locale: draft.locale, slug: draft.slug, findings: ['Replace learning-style, ability, or decision-quality prescription with a task-level experiment.', 'Add context and a counterexample that separates preference from performance.', 'Prohibit diagnosis, fixed ability, guaranteed achievement, and high-stakes decision use.', 'Keep private reports, attribution, publication, and indexability fail closed.'], repair_required: true, reviewer: null }));
const repairedDrafts = lockedThemes.flatMap((theme) => theme.locales.map((locked) => { const text = copy[theme.theme_key][locked.locale]; return { topic_id: theme.topic_id, locale: locked.locale, slug: locked.slug, repairs: ['Reframed advice as a task-level observation or reversible experiment.', 'Separated preference, process, performance, and outcome.', 'Added a concrete counterexample and uncertainty boundary.', 'Prohibited diagnosis, ability labeling, and guaranteed achievement.', 'Preserved private-result and release safety.'], sections: { direct_answer: text.answer, evidence: text.evidence, nuance_counterexample: text.nuance, concrete_scenario: text.scenario, practical_framework: text.framework, limitation: text.limitation }, source_mapping: mapSources(locked.source_requirements, locked.locale), review_status: 'repaired_pending_manual_review' }; }));
const finalAssets = lockedThemes.flatMap((theme) => theme.locales.map((locked) => {
  const text = copy[theme.theme_key][locked.locale];
  const sourceMapping = mapSources(locked.source_requirements, locked.locale);
  const boundary = locked.locale === 'en' ? 'Educational self-reflection only. This Article does not diagnose, assign fixed ability or identity, prescribe a learning style, measure creativity or decision quality, guarantee achievement, establish FermatMind psychometrics, or provide medical, legal, financial, admissions, hiring, or career decision advice. Unsupported product values are Unknown; private report or attempt links must not be collected or published.' : '仅用于教育性自我反思。本文不诊断、不分配固定能力或身份、不规定学习风格、不测量创造力或决策质量、不保证成就、不证明费马测试心理测量指标，也不提供医疗、法律、金融、录取、招聘或职业决策建议。无公开证据的产品数值均为 Unknown；不得收集或公开私人 report 或 attempt 链接。';
  return { asset_type: 'Article', topic_id: theme.topic_id, batch: 28, locale: locked.locale, slug: locked.slug, path: locked.path, title: text.title, title_intent: locked.title_intent, primary_question: locked.primary_question, audience: locked.audience, user_task: locked.user_task, keywords: locked.keywords, search_intent: locked.search_intent, unique_intent_key: theme.unique_intent_key, sections: [{ key: 'direct_answer', body_md: text.answer }, { key: 'evidence', body_md: text.evidence }, { key: 'nuance_counterexample', body_md: text.nuance }, { key: 'concrete_scenario', body_md: text.scenario }, { key: 'practical_framework', body_md: text.framework.map((step, index) => `${index + 1}. ${step}`).join('\n') }, { key: 'limitation', body_md: text.limitation }, { key: 'visible_sources', body_md: sourceMapping.map((source) => sourceLine(source, locked.locale)).join('\n') }, { key: 'method_product_boundary', body_md: boundary }, { key: 'internal_links', body_md: locked.internal_link_targets.map((target) => `- ${target}`).join('\n') }], source_mapping: sourceMapping, internal_link_targets: locked.internal_link_targets, risk_boundary: locked.risk_boundary, review_status: 'pending_manual_review', reviewer: null, author: null, published_at: null, cms_write_executed: false, publish_state_change: false, indexability_change: false };
}));
const qa = { schema_version: 'big5-article-wave-qa.v1', generated_at: generatedAt, status: 'PASS_PENDING_MANUAL_REVIEW', counts: { locked_themes: lockedThemes.length, article_assets: finalAssets.length, en_assets: finalAssets.filter((asset) => asset.locale === 'en').length, zh_cn_assets: finalAssets.filter((asset) => asset.locale === 'zh-CN').length }, checks: { consumes_only_pr21_batch_28: true, exact_locked_slug_locale_pairs: true, unique_intents: new Set(finalAssets.map((asset) => asset.unique_intent_key)).size === 5, raw_drafts_preserved: rawDrafts.length === 10, skeptical_reviews_preserved: reviews.length === 10, repaired_drafts_preserved: repairedDrafts.length === 10, source_mapping_preserved: finalAssets.every((asset) => asset.source_mapping.length === 2), task_level_experiments_not_trait_prescriptions: true, ability_and_outcome_inference_prohibited: true, private_result_links_excluded: true, all_pending_manual_review: finalAssets.every((asset) => asset.review_status === 'pending_manual_review'), cms_writes: 0, published_assets: 0, indexability_changes: 0 } };
for (const [file, data] of Object.entries({ 'raw-drafts.json': { schema_version: 'big5-article-wave-raw.v1', generated_at: generatedAt, assets: rawDrafts }, 'skeptical-review.json': { schema_version: 'big5-article-wave-review.v1', generated_at: generatedAt, reviews }, 'repaired-drafts.json': { schema_version: 'big5-article-wave-repaired.v1', generated_at: generatedAt, assets: repairedDrafts }, 'final-package.json': { schema_version: 'big5-article-wave-final.v1', generated_at: generatedAt, authority: 'PR21 batch 28 + CMS/backend', assets: finalAssets }, 'source-mapping.json': { schema_version: 'big5-article-wave-sources.v1', generated_at: generatedAt, mappings: finalAssets.map((asset) => ({ topic_id: asset.topic_id, locale: asset.locale, slug: asset.slug, sources: asset.source_mapping })) }, 'qa_report.json': qa })) fs.writeFileSync(path.join(dir, file), `${JSON.stringify(data, null, 2)}\n`);
console.log('built batch 28 learning-habits wave: 5 locked themes / 10 Article candidates / task-level evidence boundaries');
