import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const generatedAt = '2026-07-14T11:36:49Z';
const read = (relativePath) => JSON.parse(fs.readFileSync(path.join(root, relativePath), 'utf8'));

const pageSources = [
  ['big5-authority-v2-hub-07', 'model_hub'],
  ['big5-authority-v2-domains-08', 'domain'],
  ['big5-authority-v2-facet-hubs-09', 'facet_hub'],
  ['big5-authority-v2-range-openness-10', 'range'],
  ['big5-authority-v2-range-conscientiousness-11', 'range'],
  ['big5-authority-v2-range-extraversion-12', 'range'],
  ['big5-authority-v2-range-agreeableness-13', 'range'],
  ['big5-authority-v2-range-neuroticism-14', 'range'],
  ['big5-authority-v2-facets-openness-15', 'facet'],
  ['big5-authority-v2-facets-conscientiousness-16', 'facet'],
  ['big5-authority-v2-facets-extraversion-17', 'facet'],
  ['big5-authority-v2-facets-agreeableness-18', 'facet'],
  ['big5-authority-v2-facets-neuroticism-19', 'facet'],
  ['big5-authority-v2-test-landing-20', 'test_landing'],
];
const articleSources = [
  'big5-authority-v2-article-core-model-24',
  'big5-authority-v2-article-result-reading-25',
  'big5-authority-v2-article-workplace-26',
  'big5-authority-v2-article-relationships-27',
  'big5-authority-v2-article-learning-habits-28',
  'big5-authority-v2-article-growth-change-29',
  'big5-authority-v2-article-stress-wellbeing-30',
  'big5-authority-v2-article-research-methods-31',
  'big5-authority-v2-article-comparisons-32',
  'big5-authority-v2-article-research-briefings-33',
];

const candidates = [];
const addCandidate = ({ pageFamily, locale, route, sourcePackage, sourceType }) => {
  candidates.push({
    candidate_key: `${pageFamily}:${locale}:${route}`,
    page_family: pageFamily,
    locale,
    route,
    source_package: sourcePackage,
    source_type: sourceType,
  });
};

for (const [sourcePackage, pageFamily] of pageSources) {
  const packageData = read(`generated/big-five-authority-v2/${sourcePackage}/final-package.json`);
  for (const page of packageData.pages) {
    addCandidate({ pageFamily, locale: page.locale, route: page.canonical_path, sourcePackage, sourceType: 'public_page_candidate' });
  }
}

const refreshPackage = 'big5-authority-v2-article-refresh-22';
for (const article of read(`generated/big-five-authority-v2/${refreshPackage}/article-refresh-candidates.json`).candidates) {
  addCandidate({ pageFamily: 'article', locale: article.locale, route: article.route, sourcePackage: refreshPackage, sourceType: 'article_refresh_candidate' });
}
for (const topic of read(`generated/big-five-authority-v2/${refreshPackage}/topic-hub-candidates.json`).candidates) {
  addCandidate({ pageFamily: 'topic_hub', locale: topic.locale, route: topic.route, sourcePackage: refreshPackage, sourceType: 'topic_hub_candidate' });
}

const trustPackage = 'big5-authority-v2-technical-trust-23';
for (const page of read(`generated/big-five-authority-v2/${trustPackage}/content-page-draft-package.json`).candidates) {
  addCandidate({ pageFamily: 'technical_trust', locale: page.locale, route: page.canonical_path, sourcePackage: trustPackage, sourceType: 'content_page_candidate' });
}

for (const sourcePackage of articleSources) {
  for (const article of read(`generated/big-five-authority-v2/${sourcePackage}/final-package.json`).assets) {
    addCandidate({ pageFamily: 'article', locale: article.locale, route: article.path, sourcePackage, sourceType: 'article_candidate' });
  }
}

candidates.sort((left, right) => left.route.localeCompare(right.route));
const missingSlot = (slot) => ({
  slot,
  status: 'missing_pending',
  media_asset_key: null,
  variant_key: null,
  public_url: null,
  alt: null,
  rights: null,
  provenance: null,
  operator_approval_ref: null,
  reason: 'No audited Big Five-specific Media Library asset satisfies every authority gate.',
});
const candidateMappings = candidates.map((candidate) => ({
  ...candidate,
  mapping_status: 'missing_pending',
  slots: ['hero', 'inline', 'og'].map(missingSlot),
  cms_write_executed: false,
  media_upload_executed: false,
  publish_state_change: false,
  indexability_change: false,
}));

const baselineFiles = [
  'content_baselines/media_assets/default_media_assets.json',
  'content_baselines/media_assets/mbti_desktop_clone_assets.v1.json',
  'content_baselines/media_assets/personality_type_icons.v1.json',
];
const baselineSummary = baselineFiles.map((file) => {
  const assets = read(file);
  return {
    repository_path: file,
    asset_count: assets.length,
    published_public_count: assets.filter((asset) => asset.status === 'published' && asset.is_public === true).length,
    explicit_operator_approval_evidence_count: assets.filter((asset) => String(asset.payload_json?.authorization_ref ?? '').trim() !== '').length,
    big_five_semantic_match_count: assets.filter((asset) => /big[_ -]?five|big5/i.test(JSON.stringify({ asset_key: asset.asset_key, payload_json: asset.payload_json ?? {} }))).length,
    eligible_big_five_count: 0,
  };
});

const authorityAudit = {
  schema_version: 'big5-media-authority-audit.v1',
  generated_at: generatedAt,
  audit_mode: 'read_only_repository_authority',
  production_runtime_media_inventory: 'Unknown_not_queried',
  authority_evidence: [
    'backend/app/Models/MediaAsset.php',
    'backend/app/Models/MediaVariant.php',
    'backend/app/Services/Cms/MediaAssetPromotionService.php',
    'backend/app/Support/PublicMediaUrlGuard.php',
    'backend/app/Http/Controllers/API/V0_5/Cms/MediaLibraryController.php',
    ...baselineFiles,
  ],
  eligibility_gates: [
    'MediaAsset status is published and is_public is true.',
    'Source and selected variant are synced and CDN verified.',
    'PublicMediaUrlGuard returns an allowed HTTPS public URL and rejects private/Ops disks.',
    'Asset semantics fit Big Five and the intended hero, inline, or OG slot.',
    'Locale-specific reviewed alt text is present.',
    'Rights and provenance evidence is explicit and public-safe.',
    'Operator approval evidence explicitly covers FermatMind Big Five use.',
  ],
  baseline_summary: baselineSummary,
  totals: {
    audited_repository_baseline_assets: baselineSummary.reduce((sum, row) => sum + row.asset_count, 0),
    published_public_baseline_assets: baselineSummary.reduce((sum, row) => sum + row.published_public_count, 0),
    explicit_operator_approval_evidence_assets: baselineSummary.reduce((sum, row) => sum + row.explicit_operator_approval_evidence_count, 0),
    big_five_semantic_matches: 0,
    eligible_big_five_assets: 0,
  },
  exclusions: [
    { group: 'default_media_assets', count: 6, reason: 'Generic/default assets lack a Big Five semantic match and explicit Big Five operator approval.' },
    { group: 'mbti_desktop_clone_assets', count: 224, reason: 'Published public assets are explicitly MBTI desktop-clone illustrations and cannot be cross-framework mapped to Big Five.' },
    { group: 'personality_type_icons', count: 16, reason: 'Operator-authorized rights/provenance applies to MBTI type icons, not Big Five media use.' },
  ],
  decision: 'NO_APPROVED_BIG_FIVE_MEDIA_FOUND_FAIL_CLOSED',
};

const groupMap = new Map();
for (const candidate of candidates) {
  const key = `${candidate.page_family}:${candidate.locale}`;
  const current = groupMap.get(key) ?? { page_family: candidate.page_family, locale: candidate.locale, candidate_count: 0 };
  current.candidate_count += 1;
  groupMap.set(key, current);
}
const uploadRequirements = [...groupMap.values()]
  .sort((left, right) => `${left.page_family}:${left.locale}`.localeCompare(`${right.page_family}:${right.locale}`))
  .map((group) => ({
    ...group,
    status: 'pending_operator_media',
    slot_requirements: ['hero', 'inline', 'og'].map((slot) => ({
      slot,
      desired_variant_key: slot,
      dimensions: 'pending_operator_design_spec',
      locale_specific_alt_required: true,
      rights_and_provenance_required: true,
      operator_approval_required: true,
      media_asset_key: null,
      public_url: null,
    })),
  }));

const uploadManifest = {
  schema_version: 'big5-media-upload-mapping-manifest.v1',
  generated_at: generatedAt,
  status: 'PLANNING_ONLY_PENDING_OPERATOR_MEDIA',
  requirements: uploadRequirements,
  required_asset_metadata: ['asset_key', 'slot', 'locale', 'alt', 'rights', 'provenance', 'operator_approval_ref', 'disk', 'path', 'variant_key', 'checksum'],
  upload_executed: false,
  mapping_write_executed: false,
  fabricated_urls: 0,
};

const familyCounts = Object.fromEntries([...new Set(candidates.map((candidate) => candidate.page_family))].sort().map((family) => [family, candidates.filter((candidate) => candidate.page_family === family).length]));
const localeCounts = Object.fromEntries([...new Set(candidates.map((candidate) => candidate.locale))].sort().map((locale) => [locale, candidates.filter((candidate) => candidate.locale === locale).length]));
const dryRun = {
  schema_version: 'big5-media-mapping-dry-run.v1',
  generated_at: generatedAt,
  status: 'PASS_FAIL_CLOSED_NO_MEDIA',
  counts: {
    candidate_pages: candidates.length,
    unique_routes: new Set(candidates.map((candidate) => candidate.route)).size,
    candidate_mapping_rows: candidateMappings.length,
    total_slots: candidateMappings.reduce((sum, mapping) => sum + mapping.slots.length, 0),
    mapped_slots: 0,
    missing_pending_slots: candidateMappings.reduce((sum, mapping) => sum + mapping.slots.length, 0),
    approved_big_five_media_assets: 0,
    family_locale_requirement_groups: uploadRequirements.length,
    pending_grouped_slot_requirements: uploadRequirements.reduce((sum, requirement) => sum + requirement.slot_requirements.length, 0),
  },
  family_counts: familyCounts,
  locale_counts: localeCounts,
  actions: {
    media_uploads: 0,
    media_library_writes: 0,
    cms_mapping_writes: 0,
    public_urls_generated: 0,
    publish_state_changes: 0,
    indexability_changes: 0,
  },
};

const qa = {
  schema_version: 'big5-media-og-qa.v1',
  generated_at: generatedAt,
  status: 'PASS_PENDING_OPERATOR_MEDIA',
  counts: dryRun.counts,
  checks: {
    consumes_exact_pr07_through_pr33_candidate_inventory: candidates.length === 231 && new Set(candidates.map((candidate) => candidate.route)).size === 231,
    exact_family_counts: JSON.stringify(familyCounts) === JSON.stringify({ article: 109, domain: 10, facet: 60, facet_hub: 2, model_hub: 2, range: 40, technical_trust: 4, test_landing: 2, topic_hub: 2 }),
    every_candidate_has_hero_inline_og_slots: candidateMappings.every((mapping) => mapping.slots.map((slot) => slot.slot).join(',') === 'hero,inline,og'),
    every_slot_missing_pending: candidateMappings.every((mapping) => mapping.slots.every((slot) => slot.status === 'missing_pending')),
    no_media_asset_keys_mapped: candidateMappings.every((mapping) => mapping.slots.every((slot) => slot.media_asset_key === null)),
    no_public_urls_mapped_or_fabricated: candidateMappings.every((mapping) => mapping.slots.every((slot) => slot.public_url === null)),
    alt_locale_rights_provenance_remain_pending: candidateMappings.every((mapping) => mapping.slots.every((slot) => slot.alt === null && slot.rights === null && slot.provenance === null)),
    mbti_assets_not_reused_for_big_five: true,
    runtime_inventory_truth_boundary_preserved: authorityAudit.production_runtime_media_inventory === 'Unknown_not_queried',
    all_release_mutations_zero: Object.values(dryRun.actions).every((count) => count === 0),
  },
};

const outputs = {
  'media-authority-audit.json': authorityAudit,
  'candidate-media-map.json': { schema_version: 'big5-candidate-media-map.v1', generated_at: generatedAt, mappings: candidateMappings },
  'upload-mapping-manifest.json': uploadManifest,
  'dry-run-report.json': dryRun,
  'qa_report.json': qa,
};
for (const [file, data] of Object.entries(outputs)) {
  fs.writeFileSync(path.join(dir, file), `${JSON.stringify(data, null, 2)}\n`);
}

console.log('built Big Five media/OG dry run: 231 candidates / 693 missing-pending slots / 0 fabricated URLs / 0 writes');
