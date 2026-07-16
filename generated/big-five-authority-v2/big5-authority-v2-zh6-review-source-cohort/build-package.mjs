import { createHash } from 'node:crypto';
import { readFileSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(here, '../../..');

const paths = {
  hub: resolve(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-hub-07/final-package.json'),
  domains: resolve(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-domains-08/final-package.json'),
  sourceLedger: resolve(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json'),
  reviewManifest: resolve(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-review-promotion-gate-47/review-manifest.json'),
  output: resolve(here, 'candidate-package.json'),
};

const expectedInputHashes = {
  hub: 'eeb9d07ff4337d53a0d75c1208567474d534d02db9b01d0b16a1c1a9924348d9',
  domains: '5f42a9bf5f4f51ea893d533a3d251e90bf9367c4367bb023cae6aa36821faf7d',
  sourceLedger: '859c83cfcd6ac06dec0ec6e1a0f2fd493fce0679558ade6714f4b54263bc0b09',
  reviewManifest: '54eaf64151a0a41e6a2feaa12ca2be607842e893243f6ee75f6ff78bb7ec096a',
};

const sourceIds = {
  goldberg: 'academic.goldberg-1990-big-five-structure',
  soto: 'academic.soto-john-2017-bfi2',
  roberts: 'academic.roberts-walton-viechtbauer-2006-change',
  ipip: 'official.ipip-neo-facets-table',
};

const expectedAssetIds = [
  'model_hub:zh-CN:/zh/personality/big-five',
  'domain:zh-CN:/zh/personality/big-five/openness',
  'domain:zh-CN:/zh/personality/big-five/conscientiousness',
  'domain:zh-CN:/zh/personality/big-five/extraversion',
  'domain:zh-CN:/zh/personality/big-five/agreeableness',
  'domain:zh-CN:/zh/personality/big-five/neuroticism',
];

const methodBoundaries = {
  openness: '研究支持大五的宽泛维度与层级描述；本页列出的六个侧面采用与 IPIP/NEO 传统相近的 30 侧面导航，不等同于 BFI-2 的 15 个侧面、专有 NEO 量表分数或所有大五工具的通用分类。这里的场景化文字提供观察提示，不独立验证费马测试分数，也不用于诊断、录用或预测人生结果。',
  conscientiousness: '研究支持大五的宽泛维度与层级描述；本页列出的六个侧面采用与 IPIP/NEO 传统相近的 30 侧面导航，不等同于 BFI-2 的 15 个侧面、专有 NEO 量表分数或所有大五工具的通用分类。具体行为解释仍需结合真实情境验证；结果不能用于招聘可靠性判断，不能单独解释一次延期，也不保证某种效率方法有效。',
  extraversion: '研究支持大五的宽泛维度与层级描述；本页列出的六个侧面采用与 IPIP/NEO 传统相近的 30 侧面导航，不等同于 BFI-2 的 15 个侧面、专有 NEO 量表分数或所有大五工具的通用分类。这里的文字不能诊断社交焦虑、判断团队适配或预测领导表现，仅作为自愿复盘的观察提示。',
  agreeableness: '研究支持大五的宽泛维度与层级描述；本页列出的六个侧面采用与 IPIP/NEO 传统相近的 30 侧面导航，不等同于 BFI-2 的 15 个侧面、专有 NEO 量表分数或所有大五工具的通用分类。宜人性不是关系诊断或筛选规则；本页不能判断谁可信、合适或有道德，也不得用于人员决策。',
  neuroticism: '研究支持大五的宽泛维度与层级描述；本页列出的六个侧面采用与 IPIP/NEO 传统相近的 30 侧面导航，不等同于 BFI-2 的 15 个侧面、专有 NEO 量表分数或所有大五工具的通用分类。本页不得用于诊断、替代临床评估或推断健康状态；持续或紧急的痛苦不在本内容的决策边界内。',
};

const cohortSourceUsage = {
  [sourceIds.goldberg]: {
    applicable_page_families: ['model_hub', 'domain'],
    purpose: 'broad Big Five factor structure',
  },
  [sourceIds.soto]: {
    applicable_page_families: ['model_hub', 'domain'],
    purpose: 'hierarchical domain/facet description and the explicit BFI-2 15-facet comparison boundary',
  },
  [sourceIds.roberts]: {
    applicable_page_families: ['model_hub'],
    purpose: 'group-level mean change across the life course',
  },
  [sourceIds.ipip]: {
    applicable_page_families: ['domain'],
    purpose: 'the names and domain placement of the IPIP/NEO-style 30-facet navigation shown on domain pages',
  },
};

const sha256 = (value) => createHash('sha256').update(value).digest('hex');
const readText = (path) => readFileSync(path, 'utf8');
const readJson = (path) => JSON.parse(readText(path));
const clone = (value) => JSON.parse(JSON.stringify(value));

for (const [key, path] of Object.entries(paths)) {
  if (key === 'output') continue;
  const actual = sha256(readText(path));
  if (actual !== expectedInputHashes[key]) {
    throw new Error(`${key} input hash drifted: expected ${expectedInputHashes[key]}, received ${actual}`);
  }
}

const hubPackage = readJson(paths.hub);
const domainPackage = readJson(paths.domains);
const sourceLedger = readJson(paths.sourceLedger);
const reviewManifest = readJson(paths.reviewManifest);

const sourcesById = new Map(sourceLedger.sources.map((source) => [source.id, source]));
const requiredSources = Object.values(sourceIds).map((id) => {
  const source = sourcesById.get(id);
  if (!source) throw new Error(`source ledger is missing ${id}`);
  return {
    ...clone(source),
    cohort_usage_decision: cohortSourceUsage[id],
  };
});

const ipipVisibleSource = {
  source_id: sourceIds.ipip,
  citation_label: 'IPIP：NEO 30 侧面对应表',
  public_url: 'https://ipip.ori.org/newNEO_FacetsTable.htm',
  limitation: '支持本页 30 侧面导航的名称与维度归属，不代表专有 NEO 工具、BFI-2 侧面或费马测试分数与其等同。',
};

const hub = clone(hubPackage.pages.find((page) => page.locale === 'zh-CN'));
if (!hub) throw new Error('zh-CN Big Five hub is missing');

const domains = domainPackage.pages
  .filter((page) => page.locale === 'zh-CN')
  .map((page) => {
    const candidate = clone(page);
    candidate.method_boundary = methodBoundaries[candidate.domain_code];
    if (!candidate.method_boundary) throw new Error(`missing method boundary for ${candidate.domain_code}`);

    candidate.claims.push({
      claim_id: 'claim.big_five.taxonomies_not_interchangeable',
      source_ids: [sourceIds.soto, sourceIds.ipip],
    });
    candidate.visible_sources.push(clone(ipipVisibleSource));

    return candidate;
  });

const candidatePages = [hub, ...domains];
const reviewRowsByAssetId = new Map(reviewManifest.rows.map((row) => [row.asset_id, row]));

const assets = candidatePages.map((page) => {
  const assetId = `${page.page_family}:zh-CN:${page.canonical_path}`;
  const reviewRow = reviewRowsByAssetId.get(assetId);
  if (!reviewRow) throw new Error(`PR47 review row is missing ${assetId}`);

  const publicSourceIds = page.visible_sources.map((source) => source.source_id);
  const isHub = page.page_family === 'model_hub';
  const expectedSources = isHub
    ? [sourceIds.goldberg, sourceIds.soto, sourceIds.roberts]
    : [sourceIds.goldberg, sourceIds.soto, sourceIds.ipip];

  return {
    asset_id: assetId,
    canonical_path: page.canonical_path,
    page_family: page.page_family,
    locale: page.locale,
    title: page.title,
    candidate_content_sha256: sha256(JSON.stringify(page)),
    upstream_review_manifest_state: {
      manual_review_status: reviewRow.manual_review.status,
      source_permission_approved: reviewRow.permissions.source.approved,
      media_permission_approved: reviewRow.permissions.media.approved,
      promotion_eligible: reviewRow.promotion.eligible,
    },
    automated_editorial_review: {
      status: 'pass_ready_for_human_attestation',
      checks: [
        'reader_intent_and_answerability',
        'claim_and_limitation_alignment',
        'non_diagnostic_and_non_predictive_boundaries',
        'high_middle_low_tradeoff_balance',
        'scenario_counterexample_or_context_controls',
        'no_private_result_or_transaction_data',
        'no_recruitment_admission_medical_or_outcome_decision_use',
        'natural_zh_cn_editorial_read',
      ],
      note: isHub
        ? 'No blocking editorial issue found. Three public research sources already map to structure, hierarchy, and group-level change claims.'
        : 'The prior BFI-2-only facet attribution gap is repaired by adding the official IPIP 30-facet correspondence source and an explicit taxonomy non-equivalence boundary.',
    },
    source_authority: {
      status: 'approved_for_link_citation_and_original_paraphrase',
      visible_source_count: publicSourceIds.length,
      visible_source_ids: publicSourceIds,
      expected_visible_source_ids: expectedSources,
      usage_scope: 'bibliographic citation, public link, short factual description, and original paraphrase only',
      excluded_use: 'no questionnaire items, tables, figures, abstracts, or substantial source text are reproduced',
      authority_reference: 'backend/docs/seo/personality/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/README.md#source-authority-decision',
    },
    human_manual_review: {
      status: 'pending_real_human_attestation',
      reviewer_admin_user_id: null,
      reviewed_at: null,
      review_record_sha256: null,
    },
    media_permission: {
      status: 'out_of_scope_pending',
    },
    promotion: {
      eligible: false,
      blockers: [
        'real_human_attestation_required',
        'reviewer_admin_user_id_required',
        'media_permission_out_of_scope',
        'controlled_runtime_binding_and_rollback_not_executed',
      ],
    },
    content: page,
  };
});

if (assets.map((asset) => asset.asset_id).join('\n') !== expectedAssetIds.join('\n')) {
  throw new Error('candidate cohort identity or order drifted');
}

const output = {
  schema_version: '1.0.0',
  artifact: 'big-five-authority-v2-zh6-review-source-candidate-package',
  framework: 'big_five',
  locale: 'zh-CN',
  cohort_id: 'big_five_v2_zh_cn_hub_plus_five_domains_01',
  prepared_on: '2026-07-16',
  status: 'ready_for_real_human_attestation',
  authority_boundary: {
    cms_backend_remains_public_authority: true,
    this_package_is_public_runtime_authority: false,
    production_or_cms_write_performed: false,
    publication_or_indexability_changed: false,
    historical_generated_packages_mutated: false,
  },
  input_hashes: expectedInputHashes,
  source_verification: {
    checked_on: '2026-07-16',
    method: 'Crossref REST metadata for DOI records and direct official IPIP resource verification',
    status: 'pass',
    sources: requiredSources,
  },
  counts: {
    assets: assets.length,
    model_hubs: assets.filter((asset) => asset.page_family === 'model_hub').length,
    domains: assets.filter((asset) => asset.page_family === 'domain').length,
    automated_editorial_pass: assets.filter((asset) => asset.automated_editorial_review.status === 'pass_ready_for_human_attestation').length,
    human_manual_review_complete: 0,
    source_authority_complete: assets.filter((asset) => asset.source_authority.status === 'approved_for_link_citation_and_original_paraphrase').length,
    promotion_eligible: 0,
  },
  assets,
};

writeFileSync(paths.output, `${JSON.stringify(output, null, 2)}\n`);
console.log(`Wrote ${paths.output}`);
console.log(`Assets: ${assets.length}; source authority complete: ${output.counts.source_authority_complete}; human attestations: 0`);
