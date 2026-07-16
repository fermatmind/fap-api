import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const here = dirname(fileURLToPath(import.meta.url));
const repositoryRoot = resolve(here, '../../..');

const paths = {
  package: resolve(here, 'final-snapshot-package.json'),
  reviewedCandidate: resolve(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/candidate-package.json'),
  priorAttestation: resolve(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-zh6-review-source-cohort/human-review-attestation.json'),
  sourceLedger: resolve(repositoryRoot, 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json'),
  faqSource: resolve(repositoryRoot, 'generated/big-five-authority-v2-integrity-gate-02/big_five_124_integrity_candidate_v2.json'),
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

const internalPublicCopyPatterns = [
  { code: 'asset_map', pattern: /资产地图/u },
  { code: 'cms', pattern: /\bCMS\b/iu },
  { code: 'backend', pattern: /\bbackend\b/iu },
  { code: 'schema', pattern: /\bschema\b/iu },
  { code: 'json_ld', pattern: /JSON-LD/iu },
  { code: 'sitemap', pattern: /\bsitemap\b/iu },
  { code: 'llms', pattern: /\bllms(?:-full)?(?:\.txt)?\b/iu },
  { code: 'working_revision', pattern: /working[ _-]?revision/iu },
  { code: 'promotion', pattern: /\bpromotion\b/iu },
  { code: 'review_workflow', pattern: /(?:待审阅|待审核|公开草稿|候选页|审核记录)/u },
];

const forbiddenClaimPatterns = [
  /绝对准确/u,
  /最准确/u,
  /已经过权威验证/u,
  /(?:保证|确保)(?:录用|收入|职业|升学|改变|结果)/u,
  /(?:能够|可以|准确)预测(?:人生|录用|收入|健康|领导表现)/u,
  /(?:可以|适合)用于招聘筛选/u,
];

const privateDataPatterns = [
  /\/attempt(?:s)?(?:\/|$)/u,
  /\/orders?(?:\/|$)/u,
  /\/reports?\/[A-Za-z0-9_-]+/u,
  /(?:payment|token=|order_id|user_id)/iu,
];

const sha256 = (value) => createHash('sha256').update(value).digest('hex');
const readText = (path) => readFileSync(path, 'utf8');
const readJson = (path) => JSON.parse(readText(path));
const clone = (value) => JSON.parse(JSON.stringify(value));
const fail = (message) => { throw new Error(message); };
const invariant = (condition, message) => { if (!condition) fail(message); };
const normalizeQuestion = (value) => value
  .normalize('NFKC')
  .toLocaleLowerCase('zh-CN')
  .replace(/[\s?？!！。.,，:：;；'"“”‘’()（）【】\[\]_-]+/gu, '');
const normalizeText = (value) => value
  .normalize('NFKC')
  .toLocaleLowerCase('zh-CN')
  .replace(/\s+/gu, '');

const collectStrings = (value) => {
  if (typeof value === 'string') return [value];
  if (Array.isArray(value)) return value.flatMap(collectStrings);
  if (value && typeof value === 'object') return Object.values(value).flatMap(collectStrings);
  return [];
};

const sourceLedger = readJson(paths.sourceLedger);
const faqSource = readJson(paths.faqSource);
const reviewedCandidate = readJson(paths.reviewedCandidate);
const priorAttestation = readJson(paths.priorAttestation);
const claimsById = new Map(sourceLedger.claims.map((claim) => [claim.id, claim]));
const sourcesById = new Map(sourceLedger.sources.map((source) => [source.id, source]));
const faqRowsByPath = new Map(faqSource.assets.map((asset) => [asset.canonical_path, asset]));
const priorAssetsById = new Map(reviewedCandidate.assets.map((asset) => [asset.asset_id, asset]));

for (const [key, expectedHash] of Object.entries(expectedInputHashes)) {
  invariant(sha256(readText(paths[key])) === expectedHash, `${key} locked input hash drifted`);
}
invariant(priorAttestation.review_record_sha256 === '3ea9a9052e54c9595bda7c1289606f0d31269e728455a4f2124a9f7e0e58daa9', 'prior review record drifted');

const assessClaimAuthority = (pageFamily, claimMappings) => claimMappings.flatMap((mapping) => {
  const authority = claimsById.get(mapping.claim_id);
  if (!authority) return [{ code: 'claim_unknown', claim_id: mapping.claim_id }];
  const issues = [];
  if (authority.allowed_as_public_claim !== true) issues.push({ code: 'claim_not_allowed_as_public', claim_id: mapping.claim_id });
  if (!authority.applicable_page_families.includes(pageFamily)) issues.push({ code: 'claim_not_applicable_to_page_family', claim_id: mapping.claim_id });
  for (const sourceId of mapping.source_ids) {
    if (!authority.source_ids.includes(sourceId)) issues.push({ code: 'claim_source_not_authorized', claim_id: mapping.claim_id, source_id: sourceId });
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

const relockPackage = (value) => {
  for (const asset of value.assets) {
    asset.snapshot_sha256 = sha256(JSON.stringify(asset.public_snapshot));
  }
  value.cohort_snapshot_sha256 = sha256(JSON.stringify(value.assets.map((asset) => ({
    asset_id: asset.asset_id,
    snapshot_sha256: asset.snapshot_sha256,
  }))));
  const core = clone(value);
  delete core.package_payload_sha256;
  value.package_payload_sha256 = sha256(JSON.stringify(core));
  return value;
};

const validatePackage = (packageJson) => {
  invariant(packageJson.schema_version === '1.0.0', 'unexpected schema version');
  invariant(packageJson.artifact === 'big-five-authority-v2-zh6-final-public-snapshot', 'unexpected artifact type');
  invariant(packageJson.status === 'locked_pending_exact_snapshot_confirmation', 'snapshot must remain pending exact SHA confirmation');
  invariant(packageJson.cohort_id === 'big_five_v2_zh_cn_hub_plus_five_domains_01', 'cohort identity drifted');
  invariant(packageJson.authority_boundary.cms_backend_remains_public_authority === true, 'CMS/backend authority boundary is missing');
  invariant(packageJson.authority_boundary.this_package_is_public_runtime_authority === false, 'snapshot must not claim runtime authority');
  invariant(packageJson.authority_boundary.production_or_cms_write_performed === false, 'snapshot must not claim a CMS/production write');
  invariant(packageJson.authority_boundary.publication_or_indexability_changed === false, 'snapshot must not change publication or indexability');
  invariant(packageJson.authority_boundary.sitemap_llms_schema_or_frontend_changed === false, 'snapshot must not change discoverability or frontend surfaces');
  invariant(JSON.stringify(packageJson.input_hashes) === JSON.stringify(expectedInputHashes), 'input hash set drifted');
  invariant(packageJson.faq_contract.storage === 'independent_faq_field', 'FAQ must use the independent field');
  invariant(packageJson.faq_contract.dedupe_scope === 'per_asset_normalized_question', 'FAQ dedupe scope drifted');
  invariant(packageJson.assets.map((asset) => asset.asset_id).join('\n') === expectedAssetIds.join('\n'), 'asset identity or order drifted');

  const packageCore = clone(packageJson);
  delete packageCore.package_payload_sha256;
  invariant(packageJson.package_payload_sha256 === sha256(JSON.stringify(packageCore)), 'package payload SHA mismatch');
  invariant(packageJson.cohort_snapshot_sha256 === sha256(JSON.stringify(packageJson.assets.map((asset) => ({
    asset_id: asset.asset_id,
    snapshot_sha256: asset.snapshot_sha256,
  })))), 'cohort snapshot SHA mismatch');

  let faqCount = 0;
  let visibleSourceCount = 0;

  for (const asset of packageJson.assets) {
    const isHub = asset.page_family === 'model_hub';
    const priorAsset = priorAssetsById.get(asset.asset_id);
    const sourceFaq = faqRowsByPath.get(asset.canonical_path)?.faq;
    invariant(priorAsset, `${asset.asset_id} prior reviewed asset is missing`);
    invariant(asset.prior_candidate_content_sha256 === priorAsset.candidate_content_sha256, `${asset.asset_id} prior candidate hash drifted`);
    invariant(asset.locale === 'zh-CN', `${asset.asset_id} locale drifted`);
    invariant(asset.snapshot_sha256 === sha256(JSON.stringify(asset.public_snapshot)), `${asset.asset_id} snapshot SHA mismatch`);
    invariant(asset.exact_snapshot_confirmation.status === 'pending_new_sha_confirmation', `${asset.asset_id} must remain pending exact confirmation in the content package`);
    invariant(asset.exact_snapshot_confirmation.reviewer_admin_user_id === null, `${asset.asset_id} must not pre-fill the new reviewer confirmation`);
    invariant(asset.exact_snapshot_confirmation.confirmed_at === null, `${asset.asset_id} must not pre-fill the new confirmation time`);
    invariant(asset.promotion.eligible === false, `${asset.asset_id} must remain promotion-ineligible`);
    invariant(asset.promotion.blockers.includes('new_snapshot_sha_confirmation_required'), `${asset.asset_id} confirmation blocker is missing`);

    const faq = asset.public_snapshot.faq;
    invariant(Array.isArray(faq), `${asset.asset_id} FAQ must be an array`);
    invariant(faq.length === (isHub ? 5 : 6), `${asset.asset_id} FAQ count drifted`);
    const normalizedQuestions = faq.map((item) => normalizeQuestion(item.question));
    invariant(normalizedQuestions.every((question) => question.length > 0), `${asset.asset_id} has an empty normalized FAQ question`);
    invariant(new Set(normalizedQuestions).size === faq.length, `${asset.asset_id} has duplicate normalized FAQ questions`);
    invariant(JSON.stringify(faq) === JSON.stringify(sourceFaq), `${asset.asset_id} FAQ content drifted from the locked source`);

    const contentStrings = collectStrings(asset.public_snapshot.content);
    const publicStrings = collectStrings(asset.public_snapshot);
    const publicText = publicStrings.join('\n');
    for (const { code, pattern } of internalPublicCopyPatterns) {
      invariant(!pattern.test(publicText), `${asset.asset_id} exposes internal public-copy term ${code}`);
    }
    for (const pattern of forbiddenClaimPatterns) {
      invariant(!pattern.test(publicText), `${asset.asset_id} contains forbidden claim pattern ${pattern}`);
    }
    for (const pattern of privateDataPatterns) {
      invariant(!pattern.test(publicText), `${asset.asset_id} exposes private-route or transaction data ${pattern}`);
    }

    const normalizedContent = normalizeText(contentStrings.join('\n'));
    for (const item of faq) {
      invariant(!normalizedContent.includes(normalizeText(item.answer)), `${asset.asset_id} repeats an FAQ answer inside the ordinary content body`);
    }

    if (isHub) {
      invariant(Array.isArray(asset.public_snapshot.content.sections), `${asset.asset_id} Hub sections are missing`);
      for (const section of asset.public_snapshot.content.sections) {
        const sectionIdentity = [section.kind, section.key, section.heading, section.title].filter(Boolean).join(' ');
        invariant(!/(?:\bfaq\b|常见问题|常见问答|问与答)/iu.test(sectionIdentity), `${asset.asset_id} retains an ordinary FAQ section`);
      }
    } else {
      invariant(!('sections' in asset.public_snapshot.content), `${asset.asset_id} domain snapshot must not invent a sections field`);
      invariant(asset.public_snapshot.content.facets.length === 6, `${asset.asset_id} must retain six facet labels`);
      invariant(asset.public_snapshot.content.method_boundary.includes('IPIP 官方 NEO 对照表'), `${asset.asset_id} method boundary must attribute the navigation terms`);
      invariant(!asset.public_snapshot.content.method_boundary.includes('BFI-2 的 15 个侧面'), `${asset.asset_id} retains the denied taxonomy-comparison copy`);
      invariant(!asset.claim_mappings.some((mapping) => mapping.claim_id === 'claim.big_five.taxonomies_not_interchangeable'), `${asset.asset_id} retains the denied taxonomy claim`);
      const hierarchyClaim = asset.claim_mappings.find((mapping) => mapping.claim_id === 'claim.big_five.hierarchical_domains_and_facets');
      invariant(hierarchyClaim?.source_ids.includes(sourceIds.soto), `${asset.asset_id} hierarchy claim lacks the academic primary source`);
      invariant(hierarchyClaim?.source_ids.includes(sourceIds.ipip), `${asset.asset_id} hierarchy claim lacks the IPIP navigation source`);
    }

    const claimAuthorityIssues = assessClaimAuthority(asset.page_family, asset.claim_mappings);
    invariant(claimAuthorityIssues.length === 0, `${asset.asset_id} claim authority is blocked: ${JSON.stringify(claimAuthorityIssues)}`);
    invariant(asset.source_authority.status === 'approved_for_link_citation_and_original_paraphrase', `${asset.asset_id} source authority status is incomplete`);
    invariant(asset.source_authority.claim_authority_issues.length === 0, `${asset.asset_id} records source-authority issues`);
    invariant(asset.source_authority.locked_ledger_sha256 === expectedInputHashes.sourceLedger, `${asset.asset_id} source-ledger lock drifted`);

    const expectedSourceIds = isHub
      ? [sourceIds.goldberg, sourceIds.soto, sourceIds.roberts]
      : [sourceIds.goldberg, sourceIds.soto, sourceIds.ipip];
    const visibleSources = asset.public_snapshot.visible_sources;
    invariant(visibleSources.length === 3, `${asset.asset_id} must expose exactly three public sources`);
    invariant(visibleSources.map((source) => source.source_id).join('\n') === expectedSourceIds.join('\n'), `${asset.asset_id} visible source set drifted`);
    invariant(asset.source_authority.visible_source_ids.join('\n') === expectedSourceIds.join('\n'), `${asset.asset_id} source authority ids drifted`);
    for (const source of visibleSources) {
      invariant(source.public_url.startsWith('https://'), `${asset.asset_id} source URL is not public HTTPS`);
      invariant(source.citation_label.trim() !== '', `${asset.asset_id} source citation label is empty`);
      invariant(source.limitation.trim() !== '', `${asset.asset_id} source limitation is empty`);
      invariant(asset.claim_mappings.some((mapping) => mapping.source_ids.includes(source.source_id)), `${asset.asset_id} visible source ${source.source_id} is not bound to a claim`);
    }

    faqCount += faq.length;
    visibleSourceCount += visibleSources.length;
  }

  invariant(faqCount === 35, 'cohort must contain exactly 35 FAQ items');
  invariant(visibleSourceCount === 18, 'cohort must contain exactly 18 visible source rows');
  invariant(packageJson.counts.assets === 6, 'asset count summary drifted');
  invariant(packageJson.counts.model_hubs === 1, 'Hub count summary drifted');
  invariant(packageJson.counts.domains === 5, 'domain count summary drifted');
  invariant(packageJson.counts.faq_items === 35, 'FAQ count summary drifted');
  invariant(packageJson.counts.hub_faq_items === 5, 'Hub FAQ summary drifted');
  invariant(packageJson.counts.domain_faq_items === 30, 'domain FAQ summary drifted');
  invariant(packageJson.counts.visible_sources === 18, 'visible source summary drifted');
  invariant(packageJson.counts.source_authority_complete === 6, 'all six source-authority rows must be complete');
  invariant(packageJson.counts.exact_snapshot_confirmations === 0, 'content package must remain pending exact confirmation');
  invariant(packageJson.counts.promotion_eligible === 0, 'promotion eligibility must remain zero');
};

const expectFailure = (label, mutate, expectedMessage) => {
  const candidate = clone(packageJson);
  mutate(candidate);
  relockPackage(candidate);
  try {
    validatePackage(candidate);
    fail(`${label} negative control unexpectedly passed`);
  } catch (error) {
    invariant(error.message.includes(expectedMessage), `${label} failed for the wrong reason: ${error.message}`);
  }
};

const packageJson = readJson(paths.package);
validatePackage(packageJson);

expectFailure('internal-term control', (candidate) => {
  candidate.assets[0].public_snapshot.content.summary += ' CMS';
}, 'internal public-copy term cms');
expectFailure('normalized-FAQ-duplicate control', (candidate) => {
  candidate.assets[0].public_snapshot.faq[1].question = candidate.assets[0].public_snapshot.faq[0].question;
}, 'duplicate normalized FAQ questions');
expectFailure('denied-claim control', (candidate) => {
  candidate.assets[1].claim_mappings.push({
    claim_id: 'claim.big_five.taxonomies_not_interchangeable',
    source_ids: [sourceIds.soto, sourceIds.ipip],
  });
}, 'retains the denied taxonomy claim');

console.log('PASS: exact zh-CN Hub + five-domain public snapshots contain 35 independent FAQ items and 18 visible source rows.');
console.log('PASS: public title/summary/content/FAQ fields contain zero internal workflow terms, unsupported claims, private routes, ordinary Hub FAQ sections, or per-page normalized FAQ duplicates.');
console.log('PASS: all six claim maps comply with the locked source ledger; the five denied taxonomy claims and their reader-facing comparison copy are absent.');
console.log('PASS: CMS/public runtime, publication, indexability, sitemap, llms, schema, frontend, media, working revisions, and promotion remain unchanged.');
console.log(`Cohort snapshot SHA256: ${packageJson.cohort_snapshot_sha256}`);
console.log(`Package payload SHA256: ${packageJson.package_payload_sha256}`);
