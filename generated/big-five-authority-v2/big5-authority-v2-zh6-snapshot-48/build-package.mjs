import { createHash } from 'node:crypto';
import { mkdirSync, readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(here, '../../..');

const paths = {
  reviewedCandidate: resolve(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/candidate-package.json'),
  priorAttestation: resolve(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/human-review-attestation.json'),
  sourceLedger: resolve(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json'),
  faqSource: resolve(repositoryRoot, 'generated/big-five-authority-v2-integrity-gate-02/big_five_124_integrity_candidate_v2.json'),
  output: resolve(here, 'final-snapshot-package.json'),
};

const expectedInputHashes = {
  reviewedCandidate: '95a6dd439635296e75689ca4ba608019f44deff6139310ccd1667b9698f93b35',
  priorAttestation: '19f7e2865f886c4a2eee1d48ba4ee92655c08290380aaebabed8431116238054',
  sourceLedger: '859c83cfcd6ac06dec0ec6e1a0f2fd493fce0679558ade6714f4b54263bc0b09',
  faqSource: '0f83b4da7aa9606b9585485a03fd569b5acb09600a24ebe1ea734d34c2516f59',
};

const expectedAssetIds = [
  'model_hub:zh-CN:/zh/personality/big-five',
  'domain:zh-CN:/zh/personality/big-five/openness',
  'domain:zh-CN:/zh/personality/big-five/conscientiousness',
  'domain:zh-CN:/zh/personality/big-five/extraversion',
  'domain:zh-CN:/zh/personality/big-five/agreeableness',
  'domain:zh-CN:/zh/personality/big-five/neuroticism',
];

const sourceIds = {
  goldberg: 'academic.goldberg-1990-big-five-structure',
  soto: 'academic.soto-john-2017-bfi2',
  roberts: 'academic.roberts-walton-viechtbauer-2006-change',
  ipip: 'official.ipip-neo-facets-table',
};

const domainMethodBoundaries = {
  openness: '本页的六个侧面名称采用 IPIP 官方 NEO 对照表中的公开导航术语，用来把开放性宽维度拆成更具体的观察角度；这不表示费马测试计算了 IPIP 或 NEO 工具的侧面分数。场景化文字只提供复盘提示，不独立验证分数，也不用于诊断、录用或预测人生结果。',
  conscientiousness: '本页的六个侧面名称采用 IPIP 官方 NEO 对照表中的公开导航术语，用来把尽责性宽维度拆成更具体的观察角度；这不表示费马测试计算了 IPIP 或 NEO 工具的侧面分数。具体行为仍需结合真实情境验证；结果不能用于招聘可靠性判断，不能单独解释一次延期，也不保证某种效率方法有效。',
  extraversion: '本页的六个侧面名称采用 IPIP 官方 NEO 对照表中的公开导航术语，用来把外向性宽维度拆成更具体的观察角度；这不表示费马测试计算了 IPIP 或 NEO 工具的侧面分数。本页不能诊断社交焦虑、判断团队适配或预测领导表现，只提供自愿复盘的观察提示。',
  agreeableness: '本页的六个侧面名称采用 IPIP 官方 NEO 对照表中的公开导航术语，用来把宜人性宽维度拆成更具体的观察角度；这不表示费马测试计算了 IPIP 或 NEO 工具的侧面分数。宜人性不是关系诊断或筛选规则；本页不能判断谁可信、合适或有道德，也不得用于人员决策。',
  neuroticism: '本页的六个侧面名称采用 IPIP 官方 NEO 对照表中的公开导航术语，用来把神经质宽维度拆成更具体的观察角度；这不表示费马测试计算了 IPIP 或 NEO 工具的侧面分数。本页不得用于诊断、替代临床评估或推断健康状态；持续或紧急的痛苦不在本内容的决策边界内。',
};

const hubContentKeys = ['title', 'summary', 'sections', 'internal_links'];
const domainContentKeys = [
  'title',
  'summary',
  'definition',
  'range',
  'facets',
  'scenario',
  'strengths_tradeoffs',
  'combination_effects',
  'action_experiment',
  'misconceptions',
  'method_boundary',
];

const sha256 = (value) => createHash('sha256').update(value).digest('hex');
const readText = (path) => readFileSync(path, 'utf8');
const readJson = (path) => JSON.parse(readText(path));
const clone = (value) => JSON.parse(JSON.stringify(value));
const pick = (value, keys) => Object.fromEntries(keys.map((key) => {
  if (!(key in value)) throw new Error(`required public content key is missing: ${key}`);
  return [key, clone(value[key])];
}));

for (const [key, path] of Object.entries(paths)) {
  if (key === 'output') continue;
  const actualHash = sha256(readText(path));
  if (actualHash !== expectedInputHashes[key]) {
    throw new Error(`${key} input hash drifted: expected ${expectedInputHashes[key]}, received ${actualHash}`);
  }
}

const reviewedCandidate = readJson(paths.reviewedCandidate);
const priorAttestation = readJson(paths.priorAttestation);
const sourceLedger = readJson(paths.sourceLedger);
const faqSource = readJson(paths.faqSource);

if (reviewedCandidate.assets.map((asset) => asset.asset_id).join('\n') !== expectedAssetIds.join('\n')) {
  throw new Error('reviewed candidate identity or order drifted');
}
if (priorAttestation.review_record_sha256 !== '3ea9a9052e54c9595bda7c1289606f0d31269e728455a4f2124a9f7e0e58daa9') {
  throw new Error('prior human review record drifted');
}

const faqRowsByPath = new Map(faqSource.assets.map((asset) => [asset.canonical_path, asset]));
const claimsById = new Map(sourceLedger.claims.map((claim) => [claim.id, claim]));
const sourcesById = new Map(sourceLedger.sources.map((source) => [source.id, source]));

const assessClaimAuthority = (pageFamily, claimMappings) => claimMappings.flatMap((mapping) => {
  const authority = claimsById.get(mapping.claim_id);
  if (!authority) return [{ code: 'claim_unknown', claim_id: mapping.claim_id }];

  const issues = [];
  if (authority.allowed_as_public_claim !== true) {
    issues.push({ code: 'claim_not_allowed_as_public', claim_id: mapping.claim_id });
  }
  if (!authority.applicable_page_families.includes(pageFamily)) {
    issues.push({ code: 'claim_not_applicable_to_page_family', claim_id: mapping.claim_id });
  }
  for (const sourceId of mapping.source_ids) {
    if (!authority.source_ids.includes(sourceId)) {
      issues.push({ code: 'claim_source_not_authorized', claim_id: mapping.claim_id, source_id: sourceId });
    }
  }
  const hasPrimaryAcademicSource = mapping.source_ids.some((sourceId) => (
    authority.primary_source_ids.includes(sourceId)
    && sourcesById.get(sourceId)?.evidence_category === 'academic_evidence'
  ));
  if (authority.classification === 'core_scientific' && !hasPrimaryAcademicSource) {
    issues.push({ code: 'primary_academic_source_missing', claim_id: mapping.claim_id });
  }
  return issues;
});

const cleanDomainAuthority = (content) => {
  const cleaned = clone(content);
  cleaned.method_boundary = domainMethodBoundaries[cleaned.domain_code];
  if (!cleaned.method_boundary) throw new Error(`domain method boundary missing for ${cleaned.domain_code}`);

  cleaned.claims = cleaned.claims
    .filter((mapping) => mapping.claim_id !== 'claim.big_five.taxonomies_not_interchangeable')
    .map((mapping) => {
      if (mapping.claim_id !== 'claim.big_five.hierarchical_domains_and_facets') return mapping;
      return {
        ...mapping,
        source_ids: [...new Set([...mapping.source_ids, sourceIds.ipip])],
      };
    });

  cleaned.visible_sources = cleaned.visible_sources.map((source) => (
    source.source_id === sourceIds.ipip
      ? {
          ...source,
          limitation: '支持本页六个侧面导航术语的名称与维度归属；不代表费马测试报告了 IPIP 或 NEO 工具的侧面分数，也不能替代具体工具的计分和解释说明。',
        }
      : source
  ));
  return cleaned;
};

const assets = reviewedCandidate.assets.map((reviewedAsset) => {
  const isHub = reviewedAsset.page_family === 'model_hub';
  const sourceContent = isHub
    ? clone(reviewedAsset.content)
    : cleanDomainAuthority(reviewedAsset.content);
  const faqSourceRow = faqRowsByPath.get(reviewedAsset.canonical_path);
  if (!faqSourceRow) throw new Error(`FAQ source row missing for ${reviewedAsset.canonical_path}`);

  const faq = clone(faqSourceRow.faq);
  const visibleSources = clone(sourceContent.visible_sources);
  const claimMappings = clone(sourceContent.claims);
  const claimAuthorityIssues = assessClaimAuthority(reviewedAsset.page_family, claimMappings);
  if (claimAuthorityIssues.length > 0) {
    throw new Error(`${reviewedAsset.asset_id} source authority remains blocked: ${JSON.stringify(claimAuthorityIssues)}`);
  }

  const expectedSourceIds = isHub
    ? [sourceIds.goldberg, sourceIds.soto, sourceIds.roberts]
    : [sourceIds.goldberg, sourceIds.soto, sourceIds.ipip];
  if (visibleSources.map((source) => source.source_id).join('\n') !== expectedSourceIds.join('\n')) {
    throw new Error(`${reviewedAsset.asset_id} visible source set drifted`);
  }

  const publicSnapshot = {
    content: pick(sourceContent, isHub ? hubContentKeys : domainContentKeys),
    faq,
    visible_sources: visibleSources,
  };

  return {
    asset_id: reviewedAsset.asset_id,
    canonical_path: reviewedAsset.canonical_path,
    locale: reviewedAsset.locale,
    page_family: reviewedAsset.page_family,
    domain_code: isHub ? null : sourceContent.domain_code,
    prior_candidate_content_sha256: reviewedAsset.candidate_content_sha256,
    snapshot_sha256: sha256(JSON.stringify(publicSnapshot)),
    public_snapshot: publicSnapshot,
    claim_mappings: claimMappings,
    source_authority: {
      status: 'approved_for_link_citation_and_original_paraphrase',
      visible_source_count: visibleSources.length,
      visible_source_ids: visibleSources.map((source) => source.source_id),
      claim_authority_issues: [],
      locked_ledger_sha256: expectedInputHashes.sourceLedger,
    },
    exact_snapshot_confirmation: {
      status: 'pending_new_sha_confirmation',
      prior_review_record_sha256: priorAttestation.review_record_sha256,
      reviewer_admin_user_id: null,
      confirmed_at: null,
    },
    promotion: {
      eligible: false,
      blockers: [
        'new_snapshot_sha_confirmation_required',
        'author_reviewer_and_review_time_binding_out_of_scope',
        'media_authority_out_of_scope',
        'working_revision_not_created',
        'preview_not_approved',
        'promotion_not_authorized',
      ],
    },
  };
});

const cohortSnapshotSha256 = sha256(JSON.stringify(assets.map((asset) => ({
  asset_id: asset.asset_id,
  snapshot_sha256: asset.snapshot_sha256,
}))));

const packageCore = {
  schema_version: '1.0.0',
  artifact: 'big-five-authority-v2-zh6-final-public-snapshot',
  framework: 'big_five',
  locale: 'zh-CN',
  cohort_id: 'big_five_v2_zh_cn_hub_plus_five_domains_01',
  prepared_on: '2026-07-16',
  status: 'locked_pending_exact_snapshot_confirmation',
  authority_boundary: {
    cms_backend_remains_public_authority: true,
    this_package_is_public_runtime_authority: false,
    production_or_cms_write_performed: false,
    publication_or_indexability_changed: false,
    sitemap_llms_schema_or_frontend_changed: false,
    historical_packages_mutated: false,
  },
  input_hashes: expectedInputHashes,
  faq_contract: {
    storage: 'independent_faq_field',
    dedupe_scope: 'per_asset_normalized_question',
    hub_section_faq_forbidden: true,
  },
  public_copy_scan_contract: {
    fields: ['title', 'summary', 'public_content', 'faq_question', 'faq_answer'],
    excludes: ['internal_provenance', 'claim_schema_keys', 'review_documents'],
  },
  counts: {
    assets: assets.length,
    model_hubs: assets.filter((asset) => asset.page_family === 'model_hub').length,
    domains: assets.filter((asset) => asset.page_family === 'domain').length,
    faq_items: assets.reduce((count, asset) => count + asset.public_snapshot.faq.length, 0),
    hub_faq_items: assets.find((asset) => asset.page_family === 'model_hub')?.public_snapshot.faq.length ?? 0,
    domain_faq_items: assets.filter((asset) => asset.page_family === 'domain').reduce((count, asset) => count + asset.public_snapshot.faq.length, 0),
    visible_sources: assets.reduce((count, asset) => count + asset.public_snapshot.visible_sources.length, 0),
    source_authority_complete: assets.filter((asset) => asset.source_authority.status === 'approved_for_link_citation_and_original_paraphrase').length,
    exact_snapshot_confirmations: 0,
    promotion_eligible: 0,
  },
  cohort_snapshot_sha256: cohortSnapshotSha256,
  assets,
};

const output = {
  ...packageCore,
  package_payload_sha256: sha256(JSON.stringify(packageCore)),
};

mkdirSync(here, { recursive: true });
writeFileSync(paths.output, `${JSON.stringify(output, null, 2)}\n`);
console.log(`Wrote ${paths.output}`);
console.log(`Assets: ${output.counts.assets}; FAQ: ${output.counts.faq_items}; visible sources: ${output.counts.visible_sources}`);
console.log(`Cohort snapshot SHA256: ${output.cohort_snapshot_sha256}`);
console.log(`Package payload SHA256: ${output.package_payload_sha256}`);
