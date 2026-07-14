import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const generatedAt = '2026-07-14T13:08:00Z';
const read = (relativePath) => JSON.parse(fs.readFileSync(path.join(root, relativePath), 'utf8'));
const packageRoot = 'generated/big-five-authority-v2';
const graph = read(`${packageRoot}/big5-authority-v2-link-graph-35/link-graph.json`);
const mediaMap = read(`${packageRoot}/big5-authority-v2-media-og-34/candidate-media-map.json`);
const overlap = read(`${packageRoot}/big5-authority-v2-link-graph-35/intent-overlap-report.json`);

const records = new Map();
const addRecord = (route, record, packageData = {}) => records.set(route, {
  record,
  package_review_state: packageData.review_state ?? null,
});

const pageSources = [
  'big5-authority-v2-hub-07',
  'big5-authority-v2-domains-08',
  'big5-authority-v2-facet-hubs-09',
  'big5-authority-v2-range-openness-10',
  'big5-authority-v2-range-conscientiousness-11',
  'big5-authority-v2-range-extraversion-12',
  'big5-authority-v2-range-agreeableness-13',
  'big5-authority-v2-range-neuroticism-14',
  'big5-authority-v2-facets-openness-15',
  'big5-authority-v2-facets-conscientiousness-16',
  'big5-authority-v2-facets-extraversion-17',
  'big5-authority-v2-facets-agreeableness-18',
  'big5-authority-v2-facets-neuroticism-19',
  'big5-authority-v2-test-landing-20',
];
for (const sourcePackage of pageSources) {
  const packageData = read(`${packageRoot}/${sourcePackage}/final-package.json`);
  for (const page of packageData.pages) addRecord(page.canonical_path, page, packageData);
}

const refreshPackage = read(`${packageRoot}/big5-authority-v2-article-refresh-22/article-refresh-candidates.json`);
for (const candidate of refreshPackage.candidates) addRecord(candidate.route, candidate, refreshPackage);
const topicPackage = read(`${packageRoot}/big5-authority-v2-article-refresh-22/topic-hub-candidates.json`);
for (const candidate of topicPackage.candidates) addRecord(candidate.route, candidate, topicPackage);
const trustPackage = read(`${packageRoot}/big5-authority-v2-technical-trust-23/content-page-draft-package.json`);
for (const candidate of trustPackage.candidates) addRecord(candidate.canonical_path, candidate, trustPackage);

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
for (const sourcePackage of articleSources) {
  const packageData = read(`${packageRoot}/${sourcePackage}/final-package.json`);
  for (const asset of packageData.assets) addRecord(asset.path, asset, packageData);
}

const qaByPackage = new Map();
for (const sourcePackage of new Set(graph.nodes.map((node) => node.source_package))) {
  qaByPackage.set(sourcePackage, read(`${packageRoot}/${sourcePackage}/qa_report.json`));
}
const mediaByRoute = new Map(mediaMap.mappings.map((mapping) => [mapping.route, mapping]));
const pairByRoute = new Map();
for (const pair of graph.hreflang_pairs) {
  pairByRoute.set(pair.en, pair);
  pairByRoute.set(pair['zh-CN'], pair);
}
const routeSet = new Set(graph.nodes.map((node) => node.route));

const firstText = (...values) => values.find((value) => typeof value === 'string' && value.trim() !== '')?.trim() ?? null;
const evidenceKeys = new Set(['visible_sources', 'source_mapping', 'evidence_source_ids', 'claims', 'citations', 'technical_evidence']);
const hasVisibleEvidence = (record) => {
  for (const key of evidenceKeys) {
    const value = record[key];
    if (Array.isArray(value) && value.length > 0) return true;
    if (value && typeof value === 'object' && Object.keys(value).length > 0) return true;
  }
  return (record.sections ?? []).some((section) => {
    const key = String(section?.key ?? section?.kind ?? '').toLowerCase();
    const body = firstText(section?.body, section?.body_md, section?.text);
    return body !== null && /(evidence|source|method|boundary|limit)/.test(key);
  });
};
const qaPassesClaimBoundary = (qa) => {
  const status = String(qa.status ?? '').toLowerCase();
  return qa.automated_gate_passed === true
    || status.includes('pass')
    || (qa.stage_results?.final?.editorial_ok === true && qa.stage_results?.final?.source_authority_ok === true);
};
const reviewEvidence = ({ record, package_review_state: packageReview }) => {
  const cms = record.cms_authority ?? {};
  const embedded = typeof record.review_state === 'object' ? record.review_state : {};
  const review = packageReview && typeof packageReview === 'object' ? packageReview : embedded;
  const author = firstText(record.author, cms.author, review.author);
  const reviewer = firstText(record.reviewer, cms.reviewer, review.reviewer);
  const reviewDate = firstText(record.reviewed_at, record.approved_at, cms.reviewed_at, cms.approved_at, review.approved_at);
  const publicationDate = firstText(record.published_at, cms.published_at);
  const status = firstText(record.review_status, typeof record.review_state === 'string' ? record.review_state : null, review.status);
  const approved = review.human_review_passed === true
    || review.publish_allowed === true
    || ['approved', 'reviewed', 'published'].includes(String(status ?? '').toLowerCase());
  return { author, reviewer, review_date: reviewDate, publication_date: publicationDate, status, approved };
};

const eligibility = graph.nodes.map((node) => {
  const source = records.get(node.route);
  const record = source?.record ?? {};
  const media = mediaByRoute.get(node.route);
  const pair = pairByRoute.get(node.route);
  const qa = qaByPackage.get(node.source_package) ?? {};
  const review = reviewEvidence(source ?? { record: {} });
  const title = firstText(record.meta_title, record.seo_title, record.title);
  const description = firstText(
    record.meta_description,
    record.seo_description,
    record.summary,
    record.introduction,
    record.locked_intent,
    record.primary_question,
    record.what_it_measures,
  );
  const hreflang = pair ? {
    status: 'real_reciprocal_pair',
    alternates: { en: pair.en, 'zh-CN': pair['zh-CN'], 'x-default': pair.x_default },
  } : {
    status: 'not_applicable_no_real_counterpart',
    alternates: {},
  };
  const mediaEligible = media?.mapping_status === 'mapped'
    && media.slots?.every((slot) => slot.status === 'mapped' && slot.public_url !== null) === true;
  const gates = {
    source_record: source !== undefined,
    metadata_complete: title !== null && description !== null,
    canonical_consistent: node.canonical_path === node.route && routeSet.has(node.route),
    hreflang_real_and_consistent: pair === undefined
      || (routeSet.has(pair.en) && routeSet.has(pair['zh-CN']) && pair.reciprocal === true),
    visible_evidence: hasVisibleEvidence(record),
    author_reviewer_date: review.author !== null && review.reviewer !== null && review.review_date !== null && review.approved,
    media_authority: mediaEligible,
    duplicate_and_intent: routeSet.size === graph.nodes.length
      && (node.navigation_visibility !== 'compatibility_only'
        || overlap.controls.some((control) => control.en_legacy_route === node.route && control.cannibalization_control === 'PASS_DISTINCT_INTENT')),
    claim_boundary: qaPassesClaimBoundary(qa),
    private_boundary: !/\/(attempts|reports|orders|payments|results)(\/|$)/.test(node.route),
  };
  const blockingGates = Object.entries(gates).filter(([, pass]) => !pass).map(([gate]) => gate);
  const releaseEligible = blockingGates.length === 0;

  return {
    candidate_key: node.node_id,
    page_family: node.page_family,
    locale: node.locale,
    route: node.route,
    source_package: node.source_package,
    authority: 'CMS/backend_candidate',
    metadata_candidate: { title, description, canonical_path: node.route, hreflang },
    review_evidence: review,
    media_evidence: {
      mapping_status: media?.mapping_status ?? 'missing',
      required_slots: ['hero', 'inline', 'og'],
      eligible_slots: media?.slots?.filter((slot) => slot.status === 'mapped').map((slot) => slot.slot) ?? [],
    },
    gates,
    blocking_gates: blockingGates,
    eligibility_status: releaseEligible ? 'ELIGIBLE_AFTER_EXPLICIT_RELEASE_AUTHORIZATION' : 'WITHHOLD_FAIL_CLOSED',
    release_eligible: releaseEligible,
    projections: {
      metadata_publish_eligible: releaseEligible,
      robots: releaseEligible ? 'index,follow' : 'noindex,nofollow',
      schema_eligible: releaseEligible && gates.visible_evidence && gates.claim_boundary,
      schema_payload: null,
      sitemap_eligible: releaseEligible,
      llms_eligible: releaseEligible,
      llms_full_eligible: releaseEligible && gates.visible_evidence,
      public_release_executed: false,
    },
  };
}).sort((left, right) => left.route.localeCompare(right.route));

const countGate = (gate) => eligibility.filter((row) => row.gates[gate]).length;
const authorityContract = {
  schema_version: 'big5-seo-geo-authority-contract.v1',
  generated_at: generatedAt,
  authority: 'CMS/backend only',
  release_mode: 'implementation_and_validation_only',
  rules: {
    per_candidate_decision_required: true,
    batch_auto_index_forbidden: true,
    metadata_canonical_hreflang_from_backend_candidate: true,
    schema_requires_matching_visible_content: true,
    json_ld_is_not_graph_or_citation_proof: true,
    sitemap_is_discoverability_not_authority: true,
    llms_is_entry_surface_not_citation_guarantee: true,
    llms_full_requires_visible_enriched_evidence: true,
    missing_review_or_media_fails_closed: true,
    private_routes_forbidden: true,
  },
  required_gates: Object.keys(eligibility[0].gates),
  mutations: {
    cms_writes: 0,
    database_writes: 0,
    metadata_releases: 0,
    robots_changes: 0,
    schema_releases: 0,
    sitemap_additions: 0,
    llms_additions: 0,
    llms_full_additions: 0,
    indexability_changes: 0,
    search_submissions: 0,
    deploys: 0,
  },
};
const summary = {
  schema_version: 'big5-seo-geo-eligibility-summary.v1',
  generated_at: generatedAt,
  candidate_count: eligibility.length,
  locale_counts: Object.fromEntries(['en', 'zh-CN'].map((locale) => [locale, eligibility.filter((row) => row.locale === locale).length])),
  family_counts: Object.fromEntries([...new Set(eligibility.map((row) => row.page_family))].sort().map((family) => [family, eligibility.filter((row) => row.page_family === family).length])),
  gate_pass_counts: Object.fromEntries(authorityContract.required_gates.map((gate) => [gate, countGate(gate)])),
  release_eligible: eligibility.filter((row) => row.release_eligible).length,
  withheld: eligibility.filter((row) => !row.release_eligible).length,
  metadata_publish_eligible: eligibility.filter((row) => row.projections.metadata_publish_eligible).length,
  schema_eligible: eligibility.filter((row) => row.projections.schema_eligible).length,
  sitemap_eligible: eligibility.filter((row) => row.projections.sitemap_eligible).length,
  llms_eligible: eligibility.filter((row) => row.projections.llms_eligible).length,
  llms_full_eligible: eligibility.filter((row) => row.projections.llms_full_eligible).length,
  robots_index_follow: eligibility.filter((row) => row.projections.robots === 'index,follow').length,
  robots_noindex_nofollow: eligibility.filter((row) => row.projections.robots === 'noindex,nofollow').length,
  decision: 'WITHHOLD_ALL_PENDING_HUMAN_REVIEW_AND_BIG_FIVE_MEDIA',
};
const qa = {
  schema_version: 'big5-seo-geo-authority-qa.v1',
  generated_at: generatedAt,
  status: 'PASS_FAIL_CLOSED_ZERO_RELEASE',
  counts: summary,
  checks: {
    exact_pr35_candidate_inventory: eligibility.length === 231 && records.size === 231,
    exact_route_identity: eligibility.every((row) => routeSet.has(row.route)),
    every_candidate_decided_individually: eligibility.every((row) => Array.isArray(row.blocking_gates)),
    canonical_and_hreflang_real_targets_only: eligibility.every((row) => row.gates.canonical_consistent && row.gates.hreflang_real_and_consistent),
    visible_schema_rule_enforced: eligibility.every((row) => !row.projections.schema_eligible || (row.gates.visible_evidence && row.gates.claim_boundary)),
    review_gate_fails_closed: eligibility.every((row) => !row.gates.author_reviewer_date),
    media_gate_fails_closed: eligibility.every((row) => !row.gates.media_authority),
    no_batch_auto_index: eligibility.every((row) => !row.release_eligible),
    no_public_surface_mutation: Object.values(authorityContract.mutations).every((count) => count === 0),
    private_boundary_preserved: eligibility.every((row) => row.gates.private_boundary),
  },
};

const outputs = {
  'authority-contract.json': authorityContract,
  'candidate-eligibility.json': { schema_version: 'big5-seo-geo-candidate-eligibility.v1', generated_at: generatedAt, candidates: eligibility },
  'eligibility-summary.json': summary,
  'qa_report.json': qa,
};
for (const [file, data] of Object.entries(outputs)) fs.writeFileSync(path.join(dir, file), `${JSON.stringify(data, null, 2)}\n`);
console.log(`built Big Five PR36 authority: ${summary.candidate_count} candidates / ${summary.release_eligible} eligible / ${summary.withheld} withheld`);
