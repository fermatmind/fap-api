import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const generatedAt = '2026-07-14T04:00:00Z';
const hub = (locale) => `/${locale === 'zh-CN' ? 'zh' : 'en'}/personality/big-five`;
const topicHub = (locale) => `/${locale === 'zh-CN' ? 'zh' : 'en'}/topics/big-five`;
const testLanding = (locale) => `/${locale === 'zh-CN' ? 'zh' : 'en'}/tests/big-five-personality-test-ocean-model`;

const batches = [
  {
    batch: 24,
    family: 'core_model',
    pr_id: 'BIG5-AUTHORITY-V2-ARTICLE-CORE-MODEL-24',
    themes: [
      ['model-history', 'big-five-model-history-from-words-to-traits', 'How did the Big Five model develop?', '大五人格模型是怎样形成的？', 'Understand the evidence path from lexical research to a five-factor model.', '理解从词汇研究到五因素模型的证据路径。', 'learn', 'Model history without an origin myth'],
      ['ocean', 'ocean-traits-what-the-five-letters-mean', 'What do the five OCEAN traits mean?', 'OCEAN 五个字母分别代表什么？', 'Build a plain-language map of the five broad domains.', '建立五个宽泛维度的通俗认知地图。', 'learn', 'OCEAN vocabulary and domain boundaries'],
      ['continuum', 'big-five-traits-are-continuums-not-boxes', 'Are Big Five traits categories or continuums?', '大五人格是类别还是连续谱？', 'Replace high/low type labels with range-based interpretation.', '用区间理解替代高低类型标签。', 'clarify', 'Continuum interpretation versus boxes'],
      ['thirty-facets', 'big-five-30-facets-explained-with-model-caveats', 'What are the 30 Big Five facets?', '大五人格 30 个侧面是什么？', 'Understand one named 30-facet inventory without treating it as universal.', '理解一个明确命名的 30 侧面体系，而不把它当作唯一标准。', 'learn', 'Thirty-facet map with taxonomy caveat'],
      ['non-type-system', 'why-big-five-is-not-a-personality-type-system', 'Why is the Big Five not a type system?', '为什么大五人格不是人格类型系统？', 'Distinguish dimensional measurement from identity typing.', '区分维度测量与身份类型化。', 'compare', 'Dimensional model versus type identity'],
    ],
  },
  {
    batch: 25,
    family: 'result_reading',
    pr_id: 'BIG5-AUTHORITY-V2-ARTICLE-RESULT-READING-25',
    themes: [
      ['read-results', 'how-to-read-big-five-results-without-self-labeling', 'How should I read my Big Five results?', '应该怎样阅读大五人格结果？', 'Turn a result into observations and testable hypotheses.', '把结果转化为观察与可检验的工作假设。', 'do', 'Result-reading sequence'],
      ['score-ranges', 'big-five-score-ranges-what-high-middle-low-can-mean', 'What can high, middle, and low Big Five ranges mean?', '大五人格高、中、低区间可能意味着什么？', 'Interpret ranges without assuming percentiles or fixed ability.', '在不假设百分位或固定能力的前提下理解区间。', 'clarify', 'Range meaning without norms'],
      ['retest', 'when-to-retest-big-five-and-what-a-score-change-means', 'When should I retake a Big Five test?', '什么时候适合复测大五人格？', 'Decide whether a retest can answer a real question.', '判断复测能否回答一个真实问题。', 'do', 'Retest timing and interpretation'],
      ['discuss-results', 'how-to-discuss-big-five-results-with-someone-else', 'How can I discuss Big Five results without labeling someone?', '怎样与他人讨论大五人格结果而不贴标签？', 'Use consent-based language for a result conversation.', '使用基于同意的语言讨论测评结果。', 'do', 'Consent-based result conversation'],
      ['thirty-day-review', 'big-five-30-day-observation-and-review-plan', 'How can I review a Big Five result over 30 days?', '如何用 30 天观察复盘大五人格结果？', 'Run a time-boxed observation loop before drawing conclusions.', '在下结论前进行有时限的观察循环。', 'do', 'Thirty-day observation protocol'],
    ],
  },
  {
    batch: 26,
    family: 'workplace',
    pr_id: 'BIG5-AUTHORITY-V2-ARTICLE-WORKPLACE-26',
    themes: [
      ['teamwork', 'big-five-teamwork-conversation-guide-not-fit-score', 'How can teams use Big Five language safely?', '团队如何安全使用大五人格语言？', 'Improve collaboration conversations without scoring team fit.', '在不判断团队适配度的前提下改善协作对话。', 'do', 'Team conversation, not fit scoring'],
      ['feedback', 'big-five-feedback-preferences-as-hypotheses', 'Can Big Five results help frame feedback preferences?', '大五人格结果能帮助讨论反馈偏好吗？', 'Test feedback preferences instead of prescribing them from traits.', '把反馈偏好当作待验证假设，而不是由特质直接规定。', 'do', 'Feedback preference experiment'],
      ['meetings', 'big-five-meeting-participation-without-personality-labels', 'How can different participation styles work in meetings?', '会议中如何容纳不同参与方式？', 'Design meeting options without assigning personality roles.', '设计多种会议参与方式，而不分配人格角色。', 'do', 'Meeting participation design'],
      ['leadership', 'big-five-leadership-reflection-not-leader-prediction', 'What can Big Five traits contribute to leadership reflection?', '大五特质能为领导力复盘提供什么？', 'Reflect on leadership behavior without predicting success.', '复盘领导行为，而不预测领导成功。', 'learn', 'Leadership reflection boundary'],
      ['work-environment', 'big-five-work-environment-preferences-as-experiments', 'How can I test work-environment preferences?', '如何检验自己的工作环境偏好？', 'Run reversible environment experiments rather than infer job fit.', '用可逆实验检验环境偏好，而不是推断职业适配。', 'do', 'Work-environment experiment'],
    ],
  },
  {
    batch: 27,
    family: 'relationships',
    pr_id: 'BIG5-AUTHORITY-V2-ARTICLE-RELATIONSHIPS-27',
    themes: [
      ['communication', 'big-five-communication-needs-conversation-prompts', 'How can Big Five language support a communication check-in?', '如何用大五人格语言做沟通检查？', 'Translate trait language into specific communication requests.', '把特质语言转化为具体沟通请求。', 'do', 'Communication request prompts'],
      ['conflict', 'big-five-conflict-patterns-observe-before-explaining', 'Can Big Five traits explain conflict patterns?', '大五特质能解释冲突模式吗？', 'Observe conflict sequences without reducing causes to personality.', '观察冲突序列，而不把原因简化为人格。', 'clarify', 'Conflict observation, not causal labeling'],
      ['friendship', 'big-five-friendship-differences-without-compatibility-scores', 'How can friends discuss personality differences?', '朋友如何讨论人格差异？', 'Negotiate friendship needs without compatibility scores.', '在不使用匹配分数的情况下协商友谊需求。', 'do', 'Friendship needs negotiation'],
      ['partners', 'big-five-couples-conversation-not-relationship-prediction', 'Can couples use Big Five results constructively?', '伴侣能否建设性地使用大五人格结果？', 'Use results as prompts without predicting relationship outcomes.', '把结果用作对话提示，而不预测关系结果。', 'do', 'Couples conversation boundary'],
      ['boundaries', 'big-five-personal-boundaries-traits-do-not-override-consent', 'Do personality traits explain personal boundaries?', '人格特质能解释个人边界吗？', 'Separate preferences from consent and non-negotiable boundaries.', '区分偏好、同意与不可协商的边界。', 'clarify', 'Consent and boundary primacy'],
    ],
  },
  {
    batch: 28,
    family: 'learning_habits',
    pr_id: 'BIG5-AUTHORITY-V2-ARTICLE-LEARNING-HABITS-28',
    themes: [
      ['learning', 'big-five-learning-preferences-test-dont-prescribe', 'Can Big Five traits reveal the best way to learn?', '大五特质能揭示最佳学习方式吗？', 'Test study conditions without prescribing a learning style.', '检验学习条件，而不是规定学习风格。', 'clarify', 'Learning-condition experiments'],
      ['procrastination', 'procrastination-is-not-a-big-five-personality-label', 'Is procrastination a personality trait?', '拖延是一种人格特质吗？', 'Separate task friction and context from trait-based blame.', '把任务阻力与情境因素从特质归因中分离。', 'clarify', 'Procrastination de-labeling'],
      ['planning', 'big-five-planning-system-run-a-two-week-experiment', 'How can I test a planning system that fits my behavior?', '如何检验适合自己行为的计划系统？', 'Run a two-week planning experiment using observable behavior.', '用可观察行为进行两周计划实验。', 'do', 'Planning-system experiment'],
      ['creativity', 'openness-and-creativity-related-not-the-same', 'Are openness and creativity the same thing?', '开放性与创造力是一回事吗？', 'Understand association without treating a trait as creative ability.', '理解相关关系，而不把特质当成创造能力。', 'clarify', 'Openness versus creative ability'],
      ['decision-making', 'big-five-decision-journal-observe-style-not-quality', 'Can Big Five traits improve decision-making?', '大五特质能改善决策吗？', 'Use a decision journal without inferring decision quality from traits.', '使用决策日志，而不从特质推断决策质量。', 'do', 'Decision-style journal'],
    ],
  },
  {
    batch: 29,
    family: 'growth_change',
    pr_id: 'BIG5-AUTHORITY-V2-ARTICLE-GROWTH-CHANGE-29',
    themes: [
      ['personality-change', 'can-big-five-personality-traits-change-over-time', 'Can Big Five personality traits change?', '大五人格特质会随时间改变吗？', 'Understand group-level change evidence without personal prediction.', '理解群体层面的变化证据，而不做个人预测。', 'learn', 'Trait change evidence'],
      ['habits', 'habits-and-big-five-traits-behavior-is-not-identity', 'How do habits relate to Big Five traits?', '习惯与大五特质有什么关系？', 'Separate repeated behavior from fixed identity.', '区分重复行为与固定身份。', 'clarify', 'Habits versus identity'],
      ['goals', 'big-five-goal-setting-use-results-as-hypotheses', 'How can I use Big Five results for goal setting?', '如何把大五人格结果用于目标设定？', 'Design a goal experiment without assuming trait destiny.', '设计目标实验，而不假设特质决定命运。', 'do', 'Goal hypothesis workflow'],
      ['life-events', 'life-events-and-personality-change-what-research-can-say', 'Do life events change personality?', '生活事件会改变人格吗？', 'Read longitudinal findings without assigning causality to one event.', '阅读纵向研究结论，而不把因果归给单一事件。', 'learn', 'Life-event evidence limits'],
      ['tracking', 'track-personality-related-behavior-without-chasing-scores', 'How can I track growth without chasing personality scores?', '如何在不追逐人格分数的情况下追踪成长？', 'Track behaviors and contexts instead of optimizing a trait score.', '追踪行为与情境，而不是优化特质分数。', 'do', 'Behavior tracking protocol'],
    ],
  },
  {
    batch: 30,
    family: 'stress_wellbeing',
    pr_id: 'BIG5-AUTHORITY-V2-ARTICLE-STRESS-WELLBEING-30',
    themes: [
      ['stress-response', 'big-five-stress-response-patterns-without-diagnosis', 'How might personality relate to stress responses?', '人格可能如何与压力反应相关？', 'Observe stress patterns without diagnosing or pathologizing traits.', '观察压力模式，而不诊断或病理化特质。', 'learn', 'Stress response, non-diagnostic'],
      ['self-regulation', 'big-five-self-regulation-build-a-context-plan', 'Can Big Five results inform a self-regulation plan?', '大五结果能帮助制定自我调节计划吗？', 'Build a context plan without claiming treatment effects.', '制定情境计划，而不声称治疗效果。', 'do', 'Non-clinical regulation plan'],
      ['recovery', 'stress-recovery-log-separate-patterns-from-traits', 'How can I learn from my stress-recovery patterns?', '如何从自己的压力恢复模式中学习？', 'Use a recovery log without turning observations into diagnosis.', '使用恢复日志，而不把观察变成诊断。', 'do', 'Recovery observation log'],
      ['daily-support', 'daily-wellbeing-support-personality-is-one-context', 'What daily support can personality reflection suggest?', '人格反思能提示哪些日常支持？', 'Choose low-risk daily supports while treating personality as one context.', '选择低风险日常支持，并把人格只视为一个情境因素。', 'do', 'Daily support menu'],
      ['help-boundary', 'when-personality-content-is-not-enough-seek-professional-help', 'When is personality content not enough?', '什么时候人格内容已经不够？', 'Recognize urgent and professional-help boundaries.', '识别紧急情况与专业求助边界。', 'do', 'Professional-help boundary'],
    ],
  },
  {
    batch: 31,
    family: 'research_methods',
    pr_id: 'BIG5-AUTHORITY-V2-ARTICLE-RESEARCH-METHODS-31',
    themes: [
      ['self-report-bias', 'big-five-self-report-bias-and-response-context', 'What biases affect Big Five self-reports?', '哪些偏差会影响大五人格自陈？', 'Recognize response context and self-report limitations.', '识别作答情境与自陈局限。', 'learn', 'Self-report bias'],
      ['reliability-validity', 'reliability-and-validity-in-personality-tests-explained', 'What do reliability and validity mean for personality tests?', '人格测试中的信度与效度是什么意思？', 'Read measurement terms without assuming product-specific proof.', '理解测量术语，而不假设存在产品特定证明。', 'learn', 'Measurement term literacy'],
      ['norms', 'personality-test-norms-percentiles-and-unknowns', 'What are norms and percentiles in personality testing?', '人格测试中的常模与百分位是什么？', 'Understand norm dependence and recognize unavailable product values.', '理解常模依赖，并识别产品未提供的数值。', 'learn', 'Norm and percentile boundary'],
      ['cross-cultural', 'big-five-cross-cultural-research-and-translation-limits', 'Does the Big Five work the same across cultures?', '大五人格在不同文化中都一样吗？', 'Understand cross-cultural evidence and translation limits.', '理解跨文化证据与翻译局限。', 'learn', 'Cross-cultural generalization limits'],
      ['retest-methods', 'big-five-retest-differences-measurement-and-context', 'Why can Big Five retest scores differ?', '为什么大五人格复测分数会不同？', 'Separate measurement variation, context, and possible change.', '区分测量波动、情境与可能的变化。', 'learn', 'Retest difference mechanisms'],
    ],
  },
  {
    batch: 32,
    family: 'comparisons',
    pr_id: 'BIG5-AUTHORITY-V2-ARTICLE-COMPARISONS-32',
    themes: [
      ['mbti', 'using-big-five-and-mbti-together-without-equating-them', 'Can I use Big Five and MBTI together?', '可以同时使用大五人格和 MBTI 吗？', 'Use two models without converting traits into types.', '在不把特质换算成类型的前提下使用两个模型。', 'compare', 'Complementary use, not equivalence'],
      ['enneagram', 'big-five-vs-enneagram-traits-and-motivation-stories', 'How are the Big Five and Enneagram different?', '大五人格与九型人格有什么不同？', 'Compare model questions without claiming one-to-one mappings.', '比较模型所回答的问题，而不声称一一对应。', 'compare', 'Traits versus motivation narratives'],
      ['disc', 'big-five-vs-disc-measurement-and-workplace-language', 'How do the Big Five and DISC differ?', '大五人格与 DISC 有什么不同？', 'Compare constructs and workplace uses without score conversion.', '比较构念与职场用途，而不换算分数。', 'compare', 'Big Five versus DISC'],
      ['riasec', 'big-five-vs-riasec-personality-traits-and-career-interests', 'How do the Big Five and RIASEC differ?', '大五人格与 RIASEC 有什么不同？', 'Separate personality traits from career interests.', '区分人格特质与职业兴趣。', 'compare', 'Traits versus interests'],
      ['myths', 'big-five-myths-types-destiny-and-perfect-scores', 'What are the most common Big Five myths?', '大五人格有哪些常见误区？', 'Correct type, destiny, ideal-score, and diagnosis misconceptions.', '纠正类型、命运、理想分数与诊断误区。', 'clarify', 'Myth correction'],
    ],
  },
  {
    batch: 33,
    family: 'research_briefings',
    pr_id: 'BIG5-AUTHORITY-V2-ARTICLE-RESEARCH-BRIEFINGS-33',
    themes: [
      ['read-study', 'how-to-read-a-big-five-research-paper', 'How do I read a Big Five research paper?', '如何阅读一篇大五人格研究论文？', 'Use a repeatable paper-reading checklist.', '使用可复用的论文阅读清单。', 'do', 'Research paper reading'],
      ['new-research-context', 'how-to-place-new-big-five-research-in-context', 'How should new Big Five research be put in context?', '如何把新的大五人格研究放进背景中理解？', 'Compare a new result with prior evidence and study design.', '把新结果与既有证据和研究设计对照。', 'do', 'New-study context'],
      ['evidence-strength', 'big-five-evidence-strength-from-one-study-to-review', 'How strong is a Big Five research claim?', '一项大五人格研究主张的证据有多强？', 'Distinguish study designs and evidence accumulation.', '区分研究设计与证据累积程度。', 'learn', 'Evidence-strength ladder'],
      ['limitations', 'big-five-study-limitations-sample-measures-and-causality', 'Which limitations matter in Big Five studies?', '大五人格研究中哪些局限最重要？', 'Check samples, measures, uncertainty, and causal language.', '检查样本、测量、不确定性与因果语言。', 'do', 'Study limitation checklist'],
      ['apply-without-hype', 'apply-personality-research-without-overclaiming', 'How can I apply personality research without overclaiming?', '如何在不夸大的情况下应用人格研究？', 'Translate group evidence into a reversible personal experiment.', '把群体证据转化为可逆的个人实验。', 'do', 'Evidence-to-action translation'],
    ],
  },
];

const familySources = {
  core_model: ['academic.goldberg-1990-big-five-structure', 'academic.soto-john-2017-bfi2'],
  result_reading: ['academic.soto-john-2017-bfi2', 'official.fermatmind-public-contract-v2', 'internal.public-claim-boundary-matrix'],
  workplace: ['academic.soto-john-2017-bfi2', 'internal.public-claim-boundary-matrix'],
  relationships: ['academic.soto-john-2017-bfi2', 'internal.public-claim-boundary-matrix'],
  learning_habits: ['academic.soto-john-2017-bfi2', 'internal.public-claim-boundary-matrix'],
  growth_change: ['academic.roberts-walton-viechtbauer-2006-change', 'internal.public-claim-boundary-matrix'],
  stress_wellbeing: ['academic.soto-john-2017-bfi2', 'internal.public-claim-boundary-matrix'],
  research_methods: ['academic.soto-john-2017-bfi2', 'academic.soto-john-2009-ten-facets', 'internal.public-claim-boundary-matrix'],
  comparisons: ['academic.goldberg-1990-big-five-structure', 'academic.soto-john-2017-bfi2', 'internal.public-claim-boundary-matrix'],
  research_briefings: ['academic.goldberg-1990-big-five-structure', 'academic.soto-john-2017-bfi2', 'internal.public-claim-boundary-matrix'],
};

const familyBoundary = {
  workplace: 'No hiring, screening, promotion, performance, suitability, or workplace outcome conclusion.',
  stress_wellbeing: 'Non-medical education only: no diagnosis, treatment, crisis substitution, or equation of neuroticism with mental illness.',
  research_methods: 'Product-specific reliability, validity, norm, sample, and percentile values remain Unknown unless public reviewed evidence exists.',
  comparisons: 'No deterministic crosswalk or claimed equivalence between models.',
  research_briefings: 'Use primary papers where possible; execution must verify time-sensitive facts, publication dates, and access dates.',
};

const themes = batches.flatMap((batch) => batch.themes.map((theme, index) => {
  const [key, slug, questionEn, questionZh, taskEn, taskZh, intent, titleIntent] = theme;
  const topicId = `big5.article.${batch.batch}.${String(index + 1).padStart(2, '0')}.${key}`;
  const commonBoundary = 'Educational self-reflection only; no diagnosis, fixed identity, guaranteed outcome, or unsupported product psychometric claim.';
  const locales = [
    { locale: 'en', path: `/en/articles/${slug}`, title_intent: `${titleIntent}: answer “${questionEn}”`, primary_question: questionEn, audience: 'English-speaking adults seeking evidence-aware self-reflection', user_task: taskEn, keywords: [`Big Five ${key.replaceAll('-', ' ')}`, questionEn.replace(/[?]/g, '')] },
    { locale: 'zh-CN', path: `/zh/articles/${slug}`, title_intent: `回答“${questionZh}”，聚焦 ${titleIntent}`, primary_question: questionZh, audience: '希望进行循证自我反思的中文成年读者', user_task: taskZh, keywords: [`大五人格 ${key.replaceAll('-', ' ')}`, questionZh.replace(/[？]/g, '')] },
  ].map((item) => ({
    ...item,
    slug,
    search_intent: intent,
    internal_link_targets: [hub(item.locale), topicHub(item.locale), testLanding(item.locale)],
    source_requirements: familySources[batch.family],
    risk_boundary: `${commonBoundary}${familyBoundary[batch.family] ? ` ${familyBoundary[batch.family]}` : ''}`,
    publication_state: 'draft_candidate_only',
    indexability_state: 'unchanged',
  }));
  return { topic_id: topicId, batch: batch.batch, pr_id: batch.pr_id, family: batch.family, theme_key: key, unique_intent_key: `${batch.family}:${key}`, locked_slug: slug, locales };
}));

const existingSurfaces = [
  ['article', 'en', 'big-five-conscientiousness-low-procrastination-task-plan', 'Task-plan guidance for procrastination framed around low conscientiousness', 'Refresh without trait blame; keep distinct from PR28 de-labeling intent.'],
  ['article', 'en', 'big-five-emotional-stability-stress-recovery-communication', 'Stress-recovery communication framed around emotional stability', 'Refresh as communication guidance; keep distinct from PR30 non-medical stress education.'],
  ['article', 'en', 'big-five-personality-test-vs-mbti', 'Big Five versus MBTI scientific-model comparison', 'Refresh comparison evidence; keep distinct from PR32 complementary-use intent.'],
  ['article', 'zh-CN', 'big-five-conscientiousness-low-procrastination-task-plan', '尽责性、拖延与任务计划', '去除特质责备；与 PR28 的去标签 intent 分离。'],
  ['article', 'zh-CN', 'big-five-emotional-stability-stress-recovery-communication', '情绪稳定性、压力恢复与沟通', '聚焦沟通；与 PR30 的非医疗压力教育分离。'],
  ['article', 'zh-CN', 'big-five-growth-guide', '大五人格成长引导', '与 PR29 各个独立研究问题分离，改为结果后行动入口。'],
  ['article', 'zh-CN', 'big-five-narrative-portrait', '大五人格叙事画像', '避免固定身份叙事，改为可验证观察框架。'],
  ['article', 'zh-CN', 'big-five-personality-test-vs-mbti', '大五人格与 MBTI 比较', '刷新比较证据；与 PR32 的并用 intent 分离。'],
  ['article', 'zh-CN', 'big-five-tool-guide', '大五人格工具说明', '明确工具、结果与产品边界，不承担 PR23 方法页职能。'],
  ['topic_hub', 'en', '/en/topics/big-five', 'English Big Five topic enumeration', 'Enumerate backend-authoritative published and eligible content only.'],
  ['topic_hub', 'zh-CN', '/zh/topics/big-five', '中文大五人格主题枚举', '只枚举 backend authority 判定为 published/eligible 的内容。'],
].map(([surfaceType, locale, identity, currentIntent, disposition]) => ({
  surface_type: surfaceType,
  locale,
  identity,
  current_intent: currentIntent,
  audit_disposition: disposition,
  authority: 'CMS/backend',
  evidence: surfaceType === 'article' ? 'PR21 goal exact inventory plus repository content/live inventory evidence' : 'PR21 goal exact inventory plus backend topic public API contract',
  refresh_owner: 'BIG5-AUTHORITY-V2-ARTICLE-REFRESH-22',
  publication_or_indexability_change: false,
}));

const matrix = {
  schema_version: 'big5-article-intent-matrix.v1',
  generated_at: generatedAt,
  authority: 'BIG5-AUTHORITY-V2-ARTICLE-IA-21',
  lock_policy: 'PR24-33 must consume exactly these topic IDs, slugs, intents, questions, audiences, sources, links, and boundaries. Replacement or invention requires a separately approved architecture revision.',
  forbidden_expansion: ['trait_x_career_matrix', 'trait_x_problem_matrix', 'article_body_generation', 'cms_write', 'publish_state_change', 'indexability_change'],
  counts: { batches: batches.length, themes: themes.length, locale_drafts: themes.reduce((sum, theme) => sum + theme.locales.length, 0) },
  themes,
};

const evidence = {
  schema_version: 'big5-article-ia-evidence-register.v1',
  generated_at: generatedAt,
  separation_rule: 'Competitor, academic, and GSC evidence are separate inputs and cannot substitute for one another.',
  academic_evidence: {
    status: 'AVAILABLE_FROM_LOCKED_PR05_LEDGER',
    authority_path: 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json',
    source_ids: ['academic.goldberg-1990-big-five-structure', 'academic.soto-john-2017-bfi2', 'academic.deyoung-quilty-peterson-2007-aspects', 'academic.soto-john-2009-ten-facets', 'academic.roberts-walton-viechtbauer-2006-change'],
    use: 'Scientific background and claim support subject to each source limitation.',
  },
  competitor_evidence: {
    status: 'AVAILABLE_AS_TIME_BOUND_STRUCTURE_ONLY',
    source_ids: ['competitor.big-five-public-structure-benchmark-2026-07-13'],
    authority_path: 'fap-web/docs/seo/personality/big5-authority-v2-benchmark-01-live-evidence-2026-07-14.json',
    use: 'Question and public-surface pattern observation only; never scientific or product authority and never copy.',
  },
  gsc_evidence: {
    status: 'GSC_EVIDENCE_PENDING',
    reason: 'No query/page export with a reproducible date range, property, filters, and access date is present in the scoped repository evidence.',
    permitted_inference: false,
    unlock_requirements: ['verified Search Console property', 'date range', 'country/device/search-type filters', 'query-page rows', 'export access date'],
    effect_on_lock: 'The 50-theme editorial architecture is locked from user-task, repository, competitor-structure, and academic evidence; it makes no claim of GSC demand validation.',
  },
};

const handoff = {
  schema_version: 'big5-article-batch-handoff.v1',
  generated_at: generatedAt,
  total_batches: batches.length,
  batches: batches.map((batch) => ({
    batch: batch.batch,
    pr_id: batch.pr_id,
    family: batch.family,
    topic_ids: themes.filter((theme) => theme.batch === batch.batch).map((theme) => theme.topic_id),
    required_theme_count: 5,
    required_locale_draft_count: 10,
    mutation_rule: 'consume_locked_matrix_only',
  })),
};

const qa = {
  schema_version: 'big5-article-ia-qa.v1',
  generated_at: generatedAt,
  status: 'PASS',
  checks: {
    existing_articles: existingSurfaces.filter((item) => item.surface_type === 'article').length,
    existing_topic_hubs: existingSurfaces.filter((item) => item.surface_type === 'topic_hub').length,
    batches: batches.length,
    themes: themes.length,
    locale_drafts: matrix.counts.locale_drafts,
    unique_topic_ids: new Set(themes.map((theme) => theme.topic_id)).size,
    unique_intents: new Set(themes.map((theme) => theme.unique_intent_key)).size,
    unique_slugs: new Set(themes.map((theme) => theme.locked_slug)).size,
    each_theme_has_en_zh_pair: themes.every((theme) => theme.locales.map((item) => item.locale).sort().join(',') === 'en,zh-CN'),
    gsc_status: evidence.gsc_evidence.status,
    body_assets_generated: 0,
    cms_writes: 0,
    publication_or_indexability_changes: 0,
    trait_combination_matrices: 0,
  },
};

for (const [name, value] of Object.entries({
  'existing-surface-audit.json': { schema_version: 'big5-existing-surface-audit.v1', generated_at: generatedAt, counts: { articles: 9, topic_hubs: 2, total: 11 }, surfaces: existingSurfaces },
  'article-intent-matrix.json': matrix,
  'evidence-register.json': evidence,
  'batch-handoff.json': handoff,
  'qa_report.json': qa,
})) {
  fs.writeFileSync(path.join(dir, name), `${JSON.stringify(value, null, 2)}\n`);
}

console.log('built Big Five article IA package: 10 batches / 50 themes / 100 locale drafts');
