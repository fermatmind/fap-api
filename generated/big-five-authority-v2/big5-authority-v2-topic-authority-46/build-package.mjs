import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const sources = {
  release: 'generated/big-five-authority-v2/big5-authority-v2-release-gate-37/draft-import-package.json',
  media: 'generated/big-five-authority-v2/big5-authority-v2-media-authority-41/mapping-package.json',
  ledger: 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json',
  testLanding: 'generated/big-five-authority-v2/big5-authority-v2-test-landing-20/final-package.json',
};
const file = (relative) => path.join(root, relative);
const read = (relative) => JSON.parse(fs.readFileSync(file(relative), 'utf8'));
const sha256File = (relative) => crypto.createHash('sha256').update(fs.readFileSync(file(relative))).digest('hex');
const canonicalize = (value) => {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === 'object') {
    return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]));
  }
  return value;
};
const canonicalSha256 = (value) => crypto.createHash('sha256').update(JSON.stringify(canonicalize(value))).digest('hex');

const release = read(sources.release);
const media = read(sources.media);
const ledger = read(sources.ledger);
const testLanding = read(sources.testLanding);
const topicReleaseRows = release.assets.filter((row) => row.page_family === 'topic_hub');
const topicMediaRows = media.mappings.filter((row) => row.page_family === 'topic_hub');
const canonicalPages = testLanding.pages.filter((row) => row.scale_code === 'BIG5_OCEAN');
const sourceIds = [
  'academic.goldberg-1990-big-five-structure',
  'academic.soto-john-2017-bfi2',
];
const evidenceSources = sourceIds.map((sourceId) => {
  const source = ledger.sources.find((row) => row.id === sourceId);
  if (!source) throw new Error(`Missing source-ledger authority: ${sourceId}`);
  return {
    source_id: source.id,
    label: source.title,
    category: 'academic_evidence',
    authority_ref: `source-ledger:academic:${source.id.replace(/^academic\./, '')}`,
    public_url: source.public_url,
    supported_claim_ids: source.supported_claim_ids,
    limitation: source.limitation,
  };
});

if (topicReleaseRows.length !== 2 || topicMediaRows.length !== 2 || canonicalPages.length !== 2) {
  throw new Error('Expected exactly two bilingual Topic release, media, and canonical test rows.');
}

const localized = {
  en: {
    segment: 'en',
    title: 'Big Five personality: understand the model and reflect with care',
    subtitle: 'Explore five broad trait dimensions, read results as working hypotheses, and test observations through reflection, feedback, and context.',
    excerpt: 'Use this topic hub to understand the Big Five, interpret results without fixed labels, and connect trait observations to practical questions.',
    heroKicker: 'Big Five personality',
    overviewTitle: 'What the Big Five describes',
    overview: 'The Big Five describes broad patterns of Openness, Conscientiousness, Extraversion, Agreeableness, and Neuroticism. A result is a structured reference and working hypothesis, not a diagnosis or fixed identity.',
    conceptsTitle: 'How to read a result',
    concepts: 'Each dimension is continuous, and the five dimensions should be read together. Interpretation depends on context, time, language, and the instrument used. Different instruments and facet systems are not interchangeable.',
    contextTitle: 'Use in work and learning contexts',
    context: 'Trait patterns can support questions about preferred pace, feedback, collaboration, and work environment. Use them only as supplementary context alongside interests, skills, values, constraints, and real-world exploration; they do not determine occupational fit or outcomes.',
    evidenceTitle: 'Evidence and limits',
    evidence: 'Evidence base:\n- Goldberg (1990), Big-Five factor structure: https://doi.org/10.1037/0022-3514.59.6.1216\n- Soto and John (2017), BFI-2 hierarchical model: https://doi.org/10.1037/pspp0000096\n\nThese sources support broad trait structure and one hierarchical model. They do not validate FermatMind scores or predict a person’s health, career, admission, hiring, income, or future.',
    testTitle: 'Take the Big Five personality test',
    testExcerpt: 'Measure five broad trait dimensions, then treat the result as a starting point for reflection and review.',
    testBadge: 'Test',
    testCta: 'Take the test',
    seoTitle: 'Big Five personality guide and test | FermatMind',
    seoDescription: 'Understand the five broad Big Five dimensions, read results with clear limits, and continue to the backend-authoritative Big Five test.',
  },
  'zh-CN': {
    segment: 'zh',
    title: '大五人格：理解模型，并审慎反思结果',
    subtitle: '了解五个宽泛特质维度，把结果当作工作假设，并结合情境、反馈与复盘检验观察。',
    excerpt: '从这里理解大五人格、避免固定标签，并把特质观察转化为可检验的实际问题。',
    heroKicker: '大五人格',
    overviewTitle: '大五人格描述什么',
    overview: '大五人格描述开放性、尽责性、外倾性、宜人性和神经质五个宽泛维度上的相对模式。测评结果是结构化参考和工作假设，不是医疗诊断，也不是固定身份。',
    conceptsTitle: '怎样阅读结果',
    concepts: '每个维度都是连续变量，五个维度需要结合起来理解。解释会受到情境、时间、语言和具体工具影响；不同量表及其分面体系不能直接互换。',
    contextTitle: '在工作与学习情境中怎样使用',
    context: '特质模式可以帮助你提出关于节奏、反馈、协作和工作环境的问题。它只能作为补充信息，并需与兴趣、技能、价值观、现实约束和实际探索一起考虑；它不能决定职业适配或未来结果。',
    evidenceTitle: '证据与边界',
    evidence: '证据来源：\n- Goldberg（1990），大五因素结构：https://doi.org/10.1037/0022-3514.59.6.1216\n- Soto 与 John（2017），BFI-2 层级模型：https://doi.org/10.1037/pspp0000096\n\n这些来源支持宽泛特质结构及一种层级模型，但不验证费马测试分数，也不能预测个人的健康、职业、录用、升学、收入或未来。',
    testTitle: '开始大五人格测试',
    testExcerpt: '测量五个宽泛特质维度，并把结果作为反思与复盘的起点。',
    testBadge: '测试',
    testCta: '开始测试',
    seoTitle: '大五人格模型与测试 | 费马测试',
    seoDescription: '理解大五人格五个宽泛维度，审慎阅读结果，并继续进入由后端权威配置的大五人格测试。',
  },
};

const topics = Object.entries(localized).map(([locale, copy]) => {
  const route = `/${copy.segment}/topics/big-five`;
  const releaseRow = topicReleaseRows.find((row) => row.locale === locale && row.route === route);
  const mediaRow = topicMediaRows.find((row) => row.locale === locale && row.route === route);
  const canonical = canonicalPages.find((row) => row.locale === locale);
  if (!releaseRow || !mediaRow || !canonical) throw new Error(`Missing locked authority row for ${locale}`);

  return {
    asset_id: `topic_hub:${locale}:${route}`,
    locale,
    route,
    authority_surface: 'CMS topic_profiles',
    identity: { org_id: 0, topic_code: 'big-five', slug: 'big-five', locale },
    source_revision: {
      asset_id: releaseRow.asset_id,
      source_package: releaseRow.source_package,
      source_hash: releaseRow.source_hash,
    },
    revision_contract: {
      target_resolution: 'existing_identity_or_block',
      revision_operation: 'create_isolated_working_revision',
      workflow_state: 'draft_pending_manual_review',
      preserve_primary_record_identity: true,
      preserve_existing_public_runtime: true,
      public_reader_selects_working_revision: false,
      promotion_authorized: false,
    },
    snapshot: {
      profile: {
        org_id: 0,
        topic_code: 'big-five',
        slug: 'big-five',
        locale,
        title: copy.title,
        subtitle: copy.subtitle,
        excerpt: copy.excerpt,
        hero_kicker: copy.heroKicker,
        hero_quote: null,
        cover_image_url: null,
        status: 'draft',
        is_public: false,
        is_indexable: false,
        published_at: null,
        scheduled_at: null,
        schema_version: 'big5-topic-authority.v2',
        sort_order: 20,
      },
      sections: [
        { section_key: 'overview', title: copy.overviewTitle, render_variant: 'rich_text', body_md: copy.overview, body_html: null, payload_json: null, sort_order: 10, is_enabled: true },
        { section_key: 'key_concepts', title: copy.conceptsTitle, render_variant: 'rich_text', body_md: copy.concepts, body_html: null, payload_json: null, sort_order: 20, is_enabled: true },
        { section_key: 'why_it_matters', title: copy.contextTitle, render_variant: 'callout', body_md: copy.context, body_html: null, payload_json: { career_claim_mode: 'supplementary_explanation_only', recommendation_authority: false }, sort_order: 30, is_enabled: true },
        { section_key: 'who_should_read', title: copy.evidenceTitle, render_variant: 'rich_text', body_md: copy.evidence, body_html: null, payload_json: null, sort_order: 40, is_enabled: true },
      ],
      entries: [{
        entry_type: 'scale',
        group_key: 'tests',
        target_key: 'BIG5_OCEAN',
        target_locale: locale,
        title_override: copy.testTitle,
        excerpt_override: copy.testExcerpt,
        badge_label: copy.testBadge,
        cta_label: copy.testCta,
        target_url_override: null,
        payload_json: {
          canonical_authority: 'scales_registry.primary_slug',
          expected_canonical_path: canonical.canonical_path,
        },
        sort_order: 10,
        is_featured: true,
        is_enabled: true,
      }],
      seo_meta: {
        seo_title: copy.seoTitle,
        seo_description: copy.seoDescription,
        canonical_url: route,
        og_title: copy.seoTitle,
        og_description: copy.seoDescription,
        og_image_url: null,
        twitter_title: copy.seoTitle,
        twitter_description: copy.seoDescription,
        twitter_image_url: null,
        robots: 'noindex,follow',
        jsonld_overrides_json: null,
      },
      authority: {
        claim_mode: 'supplementary_explanation_only',
        recommendation_authority: false,
        diagnostic_authority: false,
        outcome_prediction_authority: false,
        visible_provenance: { author: null, reviewer: null, sources: evidenceSources },
        visible_dates: {
          published_at: null,
          reviewed_at: null,
          updated_at: null,
          resolution: 'preserve_current_published_revision_authority_or_block_at_promotion',
          forbidden_fallbacks: ['revision_created_at', 'imported_at', 'built_at', 'deployed_at', 'model_created_at', 'model_updated_at'],
        },
        media: {
          mapping_status: mediaRow.mapping_status,
          slots: mediaRow.slots,
          media_eligible: false,
          operator_approval_claimed: false,
        },
        canonical_test_target: {
          scale_code: 'BIG5_OCEAN',
          primary_slug: 'big-five-personality-test-ocean-model',
          canonical_path: canonical.canonical_path,
          source: 'scales_registry.primary_slug',
          source_package: sources.testLanding,
        },
      },
    },
    gates: {
      manual_review_complete: false,
      visible_date_eligible: false,
      visible_reviewer_eligible: false,
      media_eligible: false,
      publish_eligible: false,
      indexability_eligible: false,
      sitemap_eligible: false,
      llms_eligible: false,
    },
    blockers: ['manual_review_missing', 'visible_dates_unresolved', 'reviewer_authority_missing', 'approved_media_missing', 'promotion_not_authorized'],
  };
});

const packagePayload = {
  schema_version: 'big5-topic-authority-draft-revision.v1',
  mode: 'backend_authoritative_working_revision_candidates_zero_write',
  topic_count: 2,
  source_inventory: Object.fromEntries(Object.entries(sources).map(([key, relative]) => [key, { path: relative, sha256: sha256File(relative) }])),
  topics,
  actions: {
    database_reads: 0,
    database_writes: 0,
    cms_writes: 0,
    revision_writes: 0,
    promotion_changes: 0,
    public_release_changes: 0,
    indexability_changes: 0,
    sitemap_changes: 0,
    llms_changes: 0,
    search_submissions: 0,
    cache_operations: 0,
    deployments: 0,
  },
};
const packageSha256 = canonicalSha256(packagePayload);
const dryRun = {
  schema_version: 'big5-topic-authority-dry-run.v1',
  status: 'PASS_DRAFT_REVISION_PACKAGE_BLOCKED_FOR_PROMOTION',
  mode: 'package_only_zero_write',
  package_sha256: packageSha256,
  counts: { topic_candidates: 2, working_revision_candidates: 2, promotion_eligible: 0, blocked: 2 },
  canonical_test_targets: Object.fromEntries(topics.map((topic) => [topic.locale, topic.snapshot.authority.canonical_test_target.canonical_path])),
  blockers: [...new Set(topics.flatMap((topic) => topic.blockers))],
  actions: packagePayload.actions,
};

fs.writeFileSync(path.join(dir, 'topic-draft-revision-package.json'), `${JSON.stringify(packagePayload, null, 2)}\n`);
fs.writeFileSync(path.join(dir, 'topic-draft-revision-package.sha256'), `${packageSha256}\n`);
fs.writeFileSync(path.join(dir, 'dry-run-report.json'), `${JSON.stringify(dryRun, null, 2)}\n`);
console.log(`built PR46 Topic draft-revision package ${packageSha256}: 2 candidates / 0 promotion / 0 writes`);
