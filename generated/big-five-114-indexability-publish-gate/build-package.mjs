import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const here = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(here, '../..');
const sourcePath = path.join(root, 'generated/big-five-124-publish-import-dryrun/big_five_124_merged_v1_seed.json');
const packagePath = path.join(here, 'big_five_93_indexability_promotion_v1_seed.json');
const qaPath = path.join(here, 'qa_report.json');
const sourceRaw = fs.readFileSync(sourcePath, 'utf8');
const source = JSON.parse(sourceRaw);
const sourceSha256 = crypto.createHash('sha256').update(sourceRaw).digest('hex');

const aliasKeys = new Set([
  'emotional-stability', 'high-agreeableness', 'high-conscientiousness',
  'high-extraversion', 'high-neuroticism', 'high-openness',
  'low-agreeableness', 'low-conscientiousness', 'low-extraversion', 'low-openness',
]);

const identity = (asset) => `${asset.locale}:${asset.entity_type}:${asset.entity_key}`;
const isAlias = (asset) => asset.locale === 'zh-CN' && aliasKeys.has(asset.entity_key);
const existingPublished = source.assets.filter((asset) => asset.index_eligible === true);
const candidates = source.assets.filter((asset) => !isAlias(asset) && asset.index_eligible === false);

const assets = candidates.map((asset) => {
  const rawCanonical = String(asset.canonical?.path ?? asset.canonical_path ?? '');
  const canonicalPath = rawCanonical.startsWith('https://fermatmind.com/')
    ? new URL(rawCanonical).pathname
    : rawCanonical;

  const promoted = {
  ...asset,
  canonical_path: canonicalPath,
  canonical: { ...(asset.canonical ?? {}), path: canonicalPath },
  robots: 'index,follow',
  is_public: true,
  index_eligible: true,
  sitemap_eligible: true,
  llms_eligible: true,
  launch_state: 'published',
  review_state: 'seo_discoverability_released',
  schema: {
    ...(asset.schema ?? {}),
    draft_only: false,
    runtime_release: true,
    runtime_jsonld_enabled: true,
    runtime_release_gate: 'BIG5-114-INDEXABILITY-PUBLISH-GATE-01',
  },
  method_boundary: {
    ...(asset.method_boundary ?? {}),
    indexability_gate: 'BIG5-114-INDEXABILITY-PUBLISH-GATE-01',
  },
  evidence_notes: [
    ...(asset.evidence_notes ?? []),
    {
      source: 'BIG5-114-INDEXABILITY-PUBLISH-GATE-01',
      source_type: 'controlled_indexability_promotion',
      source_seed_sha256: sourceSha256,
      production_import_required: true,
      search_submission: false,
    },
  ],
  source_package: 'big-five-93-indexability-promotion-2026-07-11',
  source_hash: sourceSha256,
  };
  delete promoted.media;
  delete promoted.media_authority;

  return promoted;
});

const promotion = {
  package: 'big-five-93-indexability-promotion-2026-07-11',
  contract_version: 'personality_public_asset.v1',
  source_seed_sha256: sourceSha256,
  assets,
};

const identities = assets.map(identity);
const checks = {
  source_asset_count_124: source.assets.length === 124,
  existing_published_count_21: existingPublished.length === 21,
  promotion_candidate_count_93: assets.length === 93,
  candidate_identity_unique: new Set(identities).size === 93,
  redirect_aliases_excluded: assets.every((asset) => !isAlias(asset)),
  all_big_five_canonical: assets.every((asset) => asset.framework === 'big_five' && String(asset.canonical?.path ?? '').startsWith('/')),
  all_published_indexable: assets.every((asset) => asset.launch_state === 'published' && asset.robots === 'index,follow' && asset.index_eligible === true),
  all_discoverability_eligible: assets.every((asset) => asset.sitemap_eligible === true && asset.llms_eligible === true),
  content_preserved: assets.every((asset) => Array.isArray(asset.sections) && asset.sections.length > 0 && asset.sections.every((section) => typeof section.body_md === 'string' && section.body_md.trim() !== '')),
  no_bodyMd: !JSON.stringify(promotion).includes('bodyMd'),
  final_canonical_total_114: existingPublished.length + assets.length === 114,
};

const qa = {
  artifact: 'BIG5-114-INDEXABILITY-PUBLISH-GATE-01',
  status: Object.values(checks).every(Boolean) ? 'pass' : 'fail',
  source_seed_sha256: sourceSha256,
  counts: {
    source_assets: source.assets.length,
    existing_published: existingPublished.length,
    promotion_candidates: assets.length,
    redirect_aliases_excluded: source.assets.filter(isAlias).length,
    final_canonical_indexable: existingPublished.length + assets.length,
  },
  checks,
  negative_guarantees: {
    production_write: false,
    deploy: false,
    search_submission: false,
    alias_promotion: false,
  },
};

fs.writeFileSync(packagePath, `${JSON.stringify(promotion, null, 2)}\n`);
fs.writeFileSync(qaPath, `${JSON.stringify(qa, null, 2)}\n`);
if (qa.status !== 'pass') throw new Error(JSON.stringify(checks));
console.log(`promotion_candidates=${assets.length}`);
console.log(`existing_published=${existingPublished.length}`);
console.log(`final_canonical_indexable=${existingPublished.length + assets.length}`);
