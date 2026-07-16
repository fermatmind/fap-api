import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const packagePath = resolve(here, 'candidate-package.json');
const packageJson = JSON.parse(readFileSync(packagePath, 'utf8'));

const fail = (message) => {
  throw new Error(message);
};
const invariant = (condition, message) => {
  if (!condition) fail(message);
};
const sha256 = (value) => createHash('sha256').update(value).digest('hex');

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

const forbiddenPublicClaims = [
  /绝对准确/u,
  /最准确/u,
  /已经过权威验证/u,
  /(?:保证|确保)(?:录用|收入|职业|升学|改变|结果)/u,
  /(?:能够|可以|准确)预测(?:人生|录用|收入|健康|领导表现)/u,
  /(?:可以|适合)用于招聘筛选/u,
];
const privateRoutePatterns = [
  /\/attempt(?:s)?(?:\/|$)/u,
  /\/orders?(?:\/|$)/u,
  /\/reports?\/(?:[A-Za-z0-9_-]+)/u,
  /payment/u,
  /token=/u,
];
const visibleWorkflowLanguagePatterns = [
  /等待人工复审/u,
  /待审阅草稿/u,
  /公开草稿/u,
];

invariant(packageJson.schema_version === '1.0.0', 'unexpected schema version');
invariant(packageJson.status === 'ready_for_real_human_attestation', 'candidate package must remain human-attestation ready');
invariant(packageJson.authority_boundary.cms_backend_remains_public_authority === true, 'CMS/backend authority boundary must be explicit');
invariant(packageJson.authority_boundary.this_package_is_public_runtime_authority === false, 'candidate package must not claim runtime authority');
invariant(packageJson.authority_boundary.production_or_cms_write_performed === false, 'candidate package must not claim a production write');
invariant(packageJson.source_verification.status === 'pass', 'source verification must pass');
invariant(packageJson.assets.length === 6, 'cohort must contain exactly six assets');
invariant(packageJson.assets.map((asset) => asset.asset_id).join('\n') === expectedAssetIds.join('\n'), 'cohort asset identity or order drifted');

for (const [inputName, expectedHash] of Object.entries(packageJson.input_hashes)) {
  invariant(/^[a-f0-9]{64}$/u.test(expectedHash), `${inputName} input hash is invalid`);
}

const verifiedSourcesById = new Map(packageJson.source_verification.sources.map((source) => [source.id, source]));
for (const sourceId of Object.values(sourceIds)) {
  const source = verifiedSourcesById.get(sourceId);
  invariant(source, `verified source is missing ${sourceId}`);
  invariant(typeof source.public_url === 'string' && source.public_url.startsWith('https://'), `${sourceId} public URL is invalid`);
  invariant(typeof source.supported_claim === 'string' && source.supported_claim.trim() !== '', `${sourceId} supported claim is missing`);
  invariant(typeof source.limitation === 'string' && source.limitation.trim() !== '', `${sourceId} limitation is missing`);
  invariant(Array.isArray(source.cohort_usage_decision?.applicable_page_families) && source.cohort_usage_decision.applicable_page_families.length > 0, `${sourceId} cohort usage decision is missing`);
  invariant(typeof source.cohort_usage_decision?.purpose === 'string' && source.cohort_usage_decision.purpose.trim() !== '', `${sourceId} cohort usage purpose is missing`);
}
invariant(verifiedSourcesById.get(sourceIds.ipip).cohort_usage_decision.applicable_page_families.join('\n') === 'domain', 'IPIP cohort usage must be limited to domain pages');

for (const asset of packageJson.assets) {
  const isHub = asset.page_family === 'model_hub';
  const expectedSources = isHub
    ? [sourceIds.goldberg, sourceIds.soto, sourceIds.roberts]
    : [sourceIds.goldberg, sourceIds.soto, sourceIds.ipip];

  invariant(asset.locale === 'zh-CN', `${asset.asset_id} locale drifted`);
  invariant(asset.canonical_path.startsWith('/zh/personality/big-five'), `${asset.asset_id} canonical path drifted`);
  invariant(asset.candidate_content_sha256 === sha256(JSON.stringify(asset.content)), `${asset.asset_id} content hash mismatch`);
  invariant(asset.automated_editorial_review.status === 'pass_ready_for_human_attestation', `${asset.asset_id} automated editorial review did not pass`);
  invariant(asset.source_authority.status === 'approved_for_link_citation_and_original_paraphrase', `${asset.asset_id} source authority is incomplete`);
  invariant(asset.source_authority.visible_source_count === 3, `${asset.asset_id} must have three visible sources`);
  invariant(asset.source_authority.visible_source_ids.join('\n') === expectedSources.join('\n'), `${asset.asset_id} visible source set drifted`);
  invariant(asset.source_authority.expected_visible_source_ids.join('\n') === expectedSources.join('\n'), `${asset.asset_id} expected source set drifted`);
  invariant(asset.human_manual_review.status === 'pending_real_human_attestation', `${asset.asset_id} must not fabricate manual review completion`);
  invariant(asset.human_manual_review.reviewer_admin_user_id === null, `${asset.asset_id} reviewer must remain null before attestation`);
  invariant(asset.human_manual_review.reviewed_at === null, `${asset.asset_id} reviewed_at must remain null before attestation`);
  invariant(asset.human_manual_review.review_record_sha256 === null, `${asset.asset_id} review hash must remain null before attestation`);
  invariant(asset.promotion.eligible === false, `${asset.asset_id} must remain promotion-ineligible`);

  const pageText = JSON.stringify(asset.content);
  for (const pattern of forbiddenPublicClaims) {
    invariant(!pattern.test(pageText), `${asset.asset_id} contains forbidden public claim pattern ${pattern}`);
  }
  for (const pattern of privateRoutePatterns) {
    invariant(!pattern.test(pageText), `${asset.asset_id} contains private route or token pattern ${pattern}`);
  }
  for (const pattern of visibleWorkflowLanguagePatterns) {
    invariant(!pattern.test(pageText), `${asset.asset_id} exposes internal review-workflow language ${pattern}`);
  }

  invariant(Array.isArray(asset.content.visible_sources) && asset.content.visible_sources.length === 3, `${asset.asset_id} content must expose three sources`);
  for (const visibleSource of asset.content.visible_sources) {
    invariant(expectedSources.includes(visibleSource.source_id), `${asset.asset_id} has unexpected visible source ${visibleSource.source_id}`);
    invariant(visibleSource.public_url.startsWith('https://'), `${asset.asset_id} source URL is invalid`);
    invariant(visibleSource.limitation.trim() !== '', `${asset.asset_id} source limitation is missing`);
  }

  if (!isHub) {
    invariant(asset.content.facets.length === 6, `${asset.asset_id} must retain six facet navigation labels`);
    invariant(asset.content.method_boundary.includes('IPIP/NEO'), `${asset.asset_id} must name the 30-facet navigation tradition`);
    invariant(asset.content.method_boundary.includes('BFI-2 的 15 个侧面'), `${asset.asset_id} must state BFI-2 non-equivalence`);
    invariant(asset.content.method_boundary.includes('专有 NEO 量表分数'), `${asset.asset_id} must state proprietary NEO non-equivalence`);
    const taxonomyClaim = asset.content.claims.find((claim) => claim.claim_id === 'claim.big_five.taxonomies_not_interchangeable');
    invariant(taxonomyClaim, `${asset.asset_id} taxonomy boundary claim is missing`);
    invariant(taxonomyClaim.source_ids.join('\n') === [sourceIds.soto, sourceIds.ipip].join('\n'), `${asset.asset_id} taxonomy claim source mapping drifted`);
  }
}

invariant(packageJson.counts.assets === 6, 'asset count summary drifted');
invariant(packageJson.counts.model_hubs === 1, 'hub count summary drifted');
invariant(packageJson.counts.domains === 5, 'domain count summary drifted');
invariant(packageJson.counts.automated_editorial_pass === 6, 'automated editorial count summary drifted');
invariant(packageJson.counts.source_authority_complete === 6, 'source authority count summary drifted');
invariant(packageJson.counts.human_manual_review_complete === 0, 'manual review must remain zero until a real human attests');
invariant(packageJson.counts.promotion_eligible === 0, 'promotion eligibility must remain zero');

console.log('PASS: exact zh-CN hub + five-domain cohort is source-authority complete and ready for real human attestation.');
console.log('PASS: historical packages, CMS/public runtime, media permission, publication, and indexability remain unchanged.');
