import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const generatedAt = '2026-07-14T05:55:00Z';

const sources = [
  { source_id: 'academic.goldberg-1990-big-five-structure', category: 'academic_primary', label_en: 'Goldberg (1990), Big-Five factor structure', label_zh: 'Goldberg（1990）：大五因子结构', public_url: 'https://doi.org/10.1037/0022-3514.59.6.1216', supports: 'Broad five-factor structure.', limitation: 'Does not validate FermatMind scoring, norms, individual results, or outcome prediction.' },
  { source_id: 'academic.soto-john-2017-bfi2', category: 'academic_primary', label_en: 'Soto & John (2017), BFI-2 domains and facets', label_zh: 'Soto 与 John（2017）：BFI-2 维度与侧面', public_url: 'https://doi.org/10.1037/pspp0000096', supports: 'One named hierarchical domain-and-facet model.', limitation: 'Does not establish that FermatMind implements BFI-2 or inherits its psychometric results.' },
  { source_id: 'academic.deyoung-quilty-peterson-2007-aspects', category: 'academic_primary', label_en: 'DeYoung, Quilty, & Peterson (2007), ten aspects', label_zh: 'DeYoung、Quilty 与 Peterson（2007）：十个方面', public_url: 'https://doi.org/10.1037/0022-3514.93.5.880', supports: 'An aspects-level hierarchy between domains and narrower facets.', limitation: 'Supports one taxonomy; it does not make all Big Five facet systems equivalent.' },
  { source_id: 'academic.soto-john-2009-ten-facets', category: 'academic_primary', label_en: 'Soto & John (2009), ten BFI facet scales', label_zh: 'Soto 与 John（2009）：十个 BFI 侧面量表', public_url: 'https://doi.org/10.1016/j.jrp.2008.10.002', supports: 'Facet-scale convergence and differentiation for a named instrument.', limitation: 'Does not provide FermatMind product-specific reliability, validity, norms, or percentiles.' },
  { source_id: 'academic.roberts-walton-viechtbauer-2006-change', category: 'academic_meta_analysis', label_en: 'Roberts, Walton, & Viechtbauer (2006), trait change meta-analysis', label_zh: 'Roberts、Walton 与 Viechtbauer（2006）：特质变化元分析', public_url: 'https://doi.org/10.1037/0033-2909.132.1.1', supports: 'Group-level mean change patterns across longitudinal studies.', limitation: 'Cannot predict whether, why, or how one individual result will change.' },
  { source_id: 'official.ipip-neo-facets-table', category: 'official_research_resource', label_en: 'IPIP NEO facets table', label_zh: 'IPIP NEO 侧面表', public_url: 'https://ipip.ori.org/newNEO_FacetsTable.htm', supports: 'A public example of named Big Five domain and facet labels.', limitation: 'Does not establish a universal taxonomy or FermatMind instrument equivalence.' },
];

const unknowns = {
  product_reliability_coefficients: 'Unknown',
  product_validity_coefficients: 'Unknown',
  normative_population: 'Unknown',
  norm_sample_size: 'Unknown',
  percentile_calibration: 'Unknown',
  standard_error_of_measurement: 'Unknown',
  subgroup_equivalence: 'Unknown',
  predictive_accuracy: 'Unknown',
};

const localized = {
  en: {
    methodology: {
      title: 'Big Five methodology and measurement boundaries',
      summary: 'How the Big Five model is used for structured self-reflection, which product details are public, what remains Unknown, and what no result can conclude.',
      sections: [
        ['purpose', 'Purpose and scope', 'This page explains the public method boundary for FermatMind Big Five content. The assessment is a structured self-report aid for self-understanding, conversation, and reversible action experiments. It is not a medical diagnosis, employment or admissions screen, legal or financial decision tool, or prediction of a person’s future.'],
        ['model', 'Model representation', 'Big Five research commonly represents personality with broad continuous domains rather than fixed identity types. Named instruments organize narrower facets differently. FermatMind content must identify the taxonomy it uses and must not imply that one facet inventory is universal.'],
        ['measurement', 'Measurement and scoring public boundary', 'The product records self-report responses and returns a structured profile through backend scoring authority. This draft does not publish an item key, weights, reverse-coding map, transformation formula, norm table, or percentile formula. Those details cannot be inferred from editorial pages or another instrument.'],
        ['unknowns', 'Product-specific evidence status', Object.entries(unknowns).map(([key, value]) => `- ${key}: ${value}`).join('\n')],
        ['limitations', 'Known limitations', 'Self-report responses can be affected by interpretation, current context, reference group, response style, and desired self-presentation. A result is a time-bound observation, not direct proof of ability, motive, diagnosis, compatibility, employability, leadership, or future outcomes. Retest differences can reflect measurement variation, context, response differences, or change; this page cannot identify the cause for one person.'],
        ['privacy', 'Privacy boundary', 'Private answers, attempts, report links, result lookup data, orders, and payment information must not appear in public pages, sitemap, llms surfaces, public internal links, or identifiable analytics logs. Public methodology content does not make a private result public.'],
        ['change_history', 'Version and change history', 'Candidate version: big5-methodology-v1, prepared 2026-07-14. No earlier public-review history is claimed here. After CMS review, the authoritative page must display only real version, reviewer, effective-date, and change records stored by CMS.'],
        ['evidence', 'Public evidence index', sources.map((source) => `- [${source.label_en}](${source.public_url}) — Supports: ${source.supports} Limitation: ${source.limitation}`).join('\n')],
      ],
    },
    policy: {
      title: 'Big Five source, review, and correction policy',
      summary: 'How sources are classified, mapped to claims, reviewed, corrected, and kept separate from competitor observations or unsupported product claims.',
      sections: [
        ['evidence_classes', 'Evidence classes', 'Primary research and meta-analyses can support bounded scientific background claims. Official research resources can document a named taxonomy. Backend contracts and CMS records govern FermatMind product behavior and publication state. Competitor observations are structural benchmarks only. Editorial inference must be labeled and reviewed.'],
        ['selection', 'Source selection', 'Prefer original papers for scientific claims and record a stable public URL, date, supported claim, and limitation. Secondary summaries may aid discovery but cannot be the sole support for a core scientific claim. Competitor copy, ratings, prices, endorsements, and technical claims are never FermatMind evidence.'],
        ['mapping', 'Claim-to-source mapping', 'Every material scientific claim must map to a source that directly supports it. A source about a general Big Five model cannot validate FermatMind reliability, validity, norms, scoring, or individual accuracy. Unsupported product numbers remain Unknown.'],
        ['review', 'Review and release states', 'Draft preparation is not human review. Science-sensitive pages remain non-public and non-indexable until a real reviewer is recorded, the claim gate passes, publication is explicitly allowed, and operator approval is complete. This package remains pending_manual_review and makes no reviewer claim.'],
        ['corrections', 'Corrections and disagreement', 'When evidence changes or a claim is challenged, preserve the previous CMS revision, identify the affected claim and source, prepare a bounded correction, obtain the required review, and record the effective date. Do not silently rewrite review history.'],
        ['privacy', 'Evidence and privacy boundary', 'Evidence review uses public research and public-safe product contracts. It must not expose private answers, attempts, reports, orders, recovery data, payment data, or identifiable user records as proof.'],
        ['change_history', 'Version and change history', 'Candidate version: big5-source-review-policy-v1, prepared 2026-07-14. CMS review and publication history are not prefilled. The public page may display only real CMS-stored reviewers, dates, version identifiers, and correction notes.'],
        ['evidence', 'Current public evidence index', sources.map((source) => `- [${source.label_en}](${source.public_url}) — ${source.limitation}`).join('\n')],
      ],
    },
  },
  'zh-CN': {
    methodology: {
      title: '大五人格方法说明与测量边界',
      summary: '说明大五模型如何用于结构化自我反思、哪些产品细节已公开、哪些仍为 Unknown，以及结果不能得出什么结论。',
      sections: [
        ['purpose', '目的与范围', '本页说明费马测试大五人格内容的公开方法边界。测评是结构化自陈工具，用于自我理解、沟通与可逆行动实验；不是医疗诊断、招聘或录取筛选、法律或金融决策工具，也不预测个人未来。'],
        ['model', '模型表达', '大五研究通常用宽泛的连续维度表达人格，而不是固定身份类型。不同命名工具组织较窄侧面的方式并不相同。费马测试内容必须说明采用的分类体系，不能暗示某一套侧面表是唯一通用标准。'],
        ['measurement', '测量与计分公开边界', '产品记录自陈作答，并由后端评分权威返回结构化画像。本草稿不公开题目答案键、权重、反向计分表、转换公式、常模表或百分位公式，也不能从编辑页面或其他量表推断这些细节。'],
        ['unknowns', '产品特定证据状态', Object.entries(unknowns).map(([key, value]) => `- ${key}: ${value}`).join('\n')],
        ['limitations', '已知局限', '自陈作答可能受到题目理解、当前情境、参照群体、回应风格和理想自我呈现影响。结果是有时间边界的观察，不是能力、动机、诊断、匹配度、就业能力、领导力或未来结果的直接证明。复测差异可能来自测量波动、情境、回应差异或变化；本页不能为某个个人判定原因。'],
        ['privacy', '隐私边界', '私人答案、attempt、report 链接、结果找回数据、订单和支付信息不得进入公开页面、sitemap、llms、公开内链或可识别分析日志。公开方法内容不会把私人结果变成公开信息。'],
        ['change_history', '版本与变更历史', '候选版本：big5-methodology-v1，准备日期 2026-07-14。本草稿不声称存在更早的公开复审历史。CMS 复审后，权威页面只能展示 CMS 中真实的版本、reviewer、生效日期和变更记录。'],
        ['evidence', '公开证据索引', sources.map((source) => `- [${source.label_zh}](${source.public_url}) — 支持：${source.supports} 限制：${source.limitation}`).join('\n')],
      ],
    },
    policy: {
      title: '大五人格来源、复审与纠错政策',
      summary: '说明来源如何分类、映射主张、接受复审与纠错，并与竞品观察或无证据产品主张保持分离。',
      sections: [
        ['evidence_classes', '证据类别', '原始研究和元分析可支持有边界的科学背景主张；官方研究资源可说明一个明确命名的分类体系；后端 contract 与 CMS 记录决定费马测试产品行为和发布状态；竞品观察只用于结构基准；编辑推断必须标注并接受复审。'],
        ['selection', '来源选择', '科学主张优先使用原始论文，并记录稳定公开链接、日期、支持的主张与限制。二手摘要可用于发现来源，但不能单独支持核心科学主张。竞品文案、评分、价格、背书与技术主张永远不是费马测试证据。'],
        ['mapping', '主张到来源映射', '每个重要科学主张都必须映射到直接支持它的来源。关于一般大五模型的来源，不能验证费马测试的信度、效度、常模、计分或个人准确性。没有证据的产品数值保持 Unknown。'],
        ['review', '复审与发布状态', '草稿准备不等于人工复审。科学敏感页面在真实 reviewer 已记录、claim gate 通过、明确允许发布且 operator approval 完成前，必须保持非公开和不可索引。本包保持 pending_manual_review，不声称已有 reviewer。'],
        ['corrections', '纠错与分歧', '证据变化或主张受到质疑时，应保留上一版 CMS revision，标明受影响的主张与来源，准备有边界的修正，完成所需复审，并记录生效日期。不得静默改写复审历史。'],
        ['privacy', '证据与隐私边界', '证据复审使用公开研究与公开安全的产品 contract，不得把私人答案、attempt、report、订单、找回数据、支付数据或可识别用户记录当作证明。'],
        ['change_history', '版本与变更历史', '候选版本：big5-source-review-policy-v1，准备日期 2026-07-14。本包不预填 CMS 复审或发布历史。公开页只能展示 CMS 中真实存储的 reviewer、日期、版本标识与纠错说明。'],
        ['evidence', '当前公开证据索引', sources.map((source) => `- [${source.label_zh}](${source.public_url}) — ${source.limitation}`).join('\n')],
      ],
    },
  },
};

const pageDefs = [
  { key: 'methodology', slug: 'big-five-methodology', page_type: 'methodology', suffix: 'big-five/methodology' },
  { key: 'policy', slug: 'big-five-source-review-policy', page_type: 'trust', suffix: 'big-five/source-review-policy' },
];

const candidates = ['en', 'zh-CN'].flatMap((locale) => pageDefs.map((definition) => {
  const content = localized[locale][definition.key];
  const routeLocale = locale === 'en' ? 'en' : 'zh';
  return {
    asset_type: 'ContentPage',
    authority_model: 'App\\Models\\ContentPage',
    locale,
    translation_group_key: `big-five-technical-trust:${definition.slug}`,
    slug: definition.slug,
    path: `/${routeLocale}/personality/${definition.suffix}`,
    canonical_path: `/${routeLocale}/personality/${definition.suffix}`,
    kind: 'company',
    page_type: definition.page_type,
    title: content.title,
    summary: content.summary,
    headings_json: content.sections.map(([key, title]) => ({ key, title })),
    content_md: content.sections.map(([key, title, body]) => `## ${title}\n\n${body}`).join('\n\n'),
    evidence_source_ids: sources.map((source) => source.source_id),
    product_evidence_unknowns: unknowns,
    status: 'draft',
    translation_status: 'draft',
    review_state: 'science_review',
    claim_gate_status: 'not_reviewed',
    is_public: false,
    is_indexable: false,
    publish_allowed: false,
    schema_enabled: false,
    faq_schema_eligible: false,
    science_review_required: true,
    legal_review_required: false,
    operator_approval_required: true,
    owner: null,
    reviewer: null,
    published_at: null,
    last_reviewed_at: null,
    effective_at: null,
    cms_write_executed: false,
  };
}));

const qa = {
  schema_version: 'big5-technical-trust-qa.v1',
  generated_at: generatedAt,
  status: 'PASS_PENDING_SCIENCE_REVIEW',
  counts: { page_identities: 2, locales: 2, content_page_candidates: candidates.length, evidence_sources: sources.length },
  checks: {
    uses_existing_content_page_model: candidates.every((page) => page.authority_model === 'App\\Models\\ContentPage'),
    parallel_cms_stack_created: false,
    all_numeric_product_evidence_unknown: candidates.every((page) => Object.values(page.product_evidence_unknowns).every((value) => value === 'Unknown')),
    all_non_public_non_indexable_drafts: candidates.every((page) => page.status === 'draft' && page.is_public === false && page.is_indexable === false && page.publish_allowed === false),
    attribution_synthesized: 0,
    cms_writes: 0,
    production_changes: 0,
  },
};

for (const [file, data] of Object.entries({
  'content-page-draft-package.json': { schema_version: 'big5-technical-trust-content-pages.v1', generated_at: generatedAt, authority: 'CMS/backend ContentPage', candidates },
  'public-evidence-index.json': { schema_version: 'big5-public-evidence-index.v1', generated_at: generatedAt, sources },
  'qa_report.json': qa,
})) fs.writeFileSync(path.join(dir, file), `${JSON.stringify(data, null, 2)}\n`);

console.log('built Big Five technical trust package: 2 identities / 4 ContentPage drafts / all product metrics Unknown');
