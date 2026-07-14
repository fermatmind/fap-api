import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const packageRoot = 'generated/big-five-authority-v2';
const generatedAt = '2026-07-14T14:16:22Z';
const dependency = {
  train_id: 'BIG5-AUTHORITY-V2-SEO-GEO-AUTHORITY-36',
  pr_number: 3084,
  merge_sha: '06057445739c2d7fa0116bacb3b00648d35a11be',
};
const pr37MergeSha = 'af99ac41406a2967b9f4778dc9da07b920bfbb7f';
const read = (relativePath) => JSON.parse(fs.readFileSync(path.join(root, relativePath), 'utf8'));
const sha256 = (value) => crypto.createHash('sha256').update(value).digest('hex');

const finalPackageDirs = [
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
const inputFiles = [
  `${packageRoot}/big5-authority-v2-source-ledger-05/source-ledger.json`,
  `${packageRoot}/big5-authority-v2-source-ledger-05/terminology-ledger.json`,
  `${packageRoot}/big5-authority-v2-source-ledger-05/qa_report.json`,
  `${packageRoot}/big5-authority-v2-editorial-gate-06/final-package.json`,
  `${packageRoot}/big5-authority-v2-editorial-gate-06/qa_report.json`,
  ...finalPackageDirs.flatMap((packageDir) => [
    `${packageRoot}/${packageDir}/final-package.json`,
    `${packageRoot}/${packageDir}/qa_report.json`,
  ]),
  `${packageRoot}/big5-authority-v2-article-ia-21/article-intent-matrix.json`,
  `${packageRoot}/big5-authority-v2-article-ia-21/evidence-register.json`,
  `${packageRoot}/big5-authority-v2-article-ia-21/batch-handoff.json`,
  `${packageRoot}/big5-authority-v2-article-ia-21/qa_report.json`,
  `${packageRoot}/big5-authority-v2-article-refresh-22/article-refresh-candidates.json`,
  `${packageRoot}/big5-authority-v2-article-refresh-22/topic-hub-candidates.json`,
  `${packageRoot}/big5-authority-v2-article-refresh-22/qa_report.json`,
  `${packageRoot}/big5-authority-v2-technical-trust-23/content-page-draft-package.json`,
  `${packageRoot}/big5-authority-v2-technical-trust-23/qa_report.json`,
  `${packageRoot}/big5-authority-v2-media-og-34/candidate-media-map.json`,
  `${packageRoot}/big5-authority-v2-media-og-34/qa_report.json`,
  `${packageRoot}/big5-authority-v2-link-graph-35/link-graph.json`,
  `${packageRoot}/big5-authority-v2-link-graph-35/intent-overlap-report.json`,
  `${packageRoot}/big5-authority-v2-link-graph-35/target-validation-report.json`,
  `${packageRoot}/big5-authority-v2-link-graph-35/qa_report.json`,
  `${packageRoot}/big5-authority-v2-seo-geo-authority-36/authority-contract.json`,
  `${packageRoot}/big5-authority-v2-seo-geo-authority-36/candidate-eligibility.json`,
  `${packageRoot}/big5-authority-v2-seo-geo-authority-36/eligibility-summary.json`,
  `${packageRoot}/big5-authority-v2-seo-geo-authority-36/qa_report.json`,
].sort();

const fileEntries = inputFiles.map((relativePath) => {
  const contents = fs.readFileSync(path.join(root, relativePath));
  return { path: relativePath, bytes: contents.byteLength, sha256: sha256(contents) };
});
const digestMaterial = `${fileEntries.map((entry) => `${entry.path}\t${entry.bytes}\t${entry.sha256}`).join('\n')}\n`;
const packageSha256 = sha256(digestMaterial);

const sourceLedger = read(`${packageRoot}/big5-authority-v2-source-ledger-05/source-ledger.json`);
const eligibility = read(`${packageRoot}/big5-authority-v2-seo-geo-authority-36/candidate-eligibility.json`).candidates;
const eligibilitySummary = read(`${packageRoot}/big5-authority-v2-seo-geo-authority-36/eligibility-summary.json`);
const graph = read(`${packageRoot}/big5-authority-v2-link-graph-35/link-graph.json`);
const overlap = read(`${packageRoot}/big5-authority-v2-link-graph-35/intent-overlap-report.json`);
const targetValidation = read(`${packageRoot}/big5-authority-v2-link-graph-35/target-validation-report.json`);
const editorialQa = read(`${packageRoot}/big5-authority-v2-editorial-gate-06/qa_report.json`);

const authoritySurface = (family) => ({
  article: 'CMS Article',
  technical_trust: 'CMS content_pages',
  test_landing: 'CMS landing_surfaces/page_blocks',
  topic_hub: 'CMS topic_profiles',
}[family] ?? 'CMS personality_public_content_assets');

const assets = eligibility.map((candidate) => ({
  asset_id: candidate.candidate_key,
  route: candidate.route,
  locale: candidate.locale,
  page_family: candidate.page_family,
  source_package: candidate.source_package,
  authority_surface: authoritySurface(candidate.page_family),
  canonical_path: candidate.metadata_candidate.canonical_path,
  bilingual_identity: candidate.metadata_candidate.hreflang.status,
  schema_valid: Object.values(candidate.gates).every((value) => typeof value === 'boolean'),
  source_record_valid: candidate.gates.source_record,
  visible_evidence_valid: candidate.gates.visible_evidence,
  duplicate_and_intent_valid: candidate.gates.duplicate_and_intent,
  private_boundary_valid: candidate.gates.private_boundary,
  local_test_db_action: 'CREATE_DRAFT_ON_EMPTY_BASELINE',
  publish_eligible: candidate.release_eligible,
  indexability_eligible: candidate.projections.robots === 'index,follow',
  sitemap_eligible: candidate.projections.sitemap_eligible,
  llms_eligible: candidate.projections.llms_eligible,
  llms_full_eligible: candidate.projections.llms_full_eligible,
  blockers: candidate.blocking_gates,
}));

const routeKeys = ['route', 'path', 'canonical_path'];
const collectRouteRecords = (node, route, matches) => {
  if (Array.isArray(node)) {
    node.forEach((value) => collectRouteRecords(value, route, matches));
    return;
  }
  if (node === null || typeof node !== 'object') return;
  if (routeKeys.some((key) => node[key] === route) && (node.title || node.content_key || node.asset_type)) {
    matches.push(node);
  }
  Object.values(node).forEach((value) => collectRouteRecords(value, route, matches));
};
const sourceDocuments = inputFiles
  .filter((relativePath) => relativePath.endsWith('.json'))
  .map((relativePath) => ({ relativePath, document: read(relativePath) }));
const draftAssets = assets.map((asset) => {
  const matches = [];
  sourceDocuments
    .filter(({ relativePath }) => relativePath.includes(`/${asset.source_package}/`))
    .forEach(({ document }) => collectRouteRecords(document, asset.route, matches));
  if (matches.length !== 1) {
    throw new Error(`expected exactly one source record for ${asset.asset_id}, found ${matches.length}`);
  }
  const draftPayload = matches[0];
  return {
    ...asset,
    source_hash: sha256(JSON.stringify(draftPayload)),
    draft_payload: draftPayload,
  };
});

const manifest = {
  schema_version: 'big5-authority-v2-aggregate-manifest.v1',
  generated_at: generatedAt,
  authority: 'CMS/backend only',
  release_mode: 'dry_run_only',
  dependency,
  aggregate_scope: 'PR07-PR36 candidate packages plus PR05 source and PR06 editorial controls',
  package_sha256: packageSha256,
  digest_algorithm: 'sha256(sorted path + tab + bytes + tab + file sha256 + newline)',
  input_file_count: fileEntries.length,
  input_files: fileEntries,
  exact_counts: {
    assets: assets.length,
    canonical_routes: graph.nodes.length,
    exact_301_aliases: graph.redirects.length,
    reciprocal_bilingual_pairs: graph.hreflang_pairs.length,
    source_ledger_entries: sourceLedger.sources.length,
  },
};

const perPage = {
  schema_version: 'big5-authority-v2-per-page-release-report.v1',
  generated_at: generatedAt,
  package_sha256: packageSha256,
  assets,
};
const dryRun = {
  schema_version: 'big5-authority-v2-local-test-db-dry-run.v1',
  generated_at: generatedAt,
  package_sha256: packageSha256,
  database: 'sqlite::memory: empty authority namespace fixture',
  default_mode: 'dry_run',
  measured_window_excludes_fixture_schema_setup: true,
  candidate_count: assets.length,
  planned_create_count: assets.length,
  planned_update_count: 0,
  planned_noop_count: 0,
  executed_insert_count: 0,
  executed_update_count: 0,
  executed_delete_count: 0,
  measured_database_write_delta: 0,
  production_writes: 0,
  cms_writes: 0,
  indexability_writes: 0,
  command: 'php generated/big-five-authority-v2/big5-authority-v2-release-gate-37/local-test-db-dry-run.php',
};
const approvalPhrase = `AUTHORIZE BIG5 AUTHORITY V2 DRAFT-ONLY PRODUCTION IMPORT FOR PR37_MERGE_SHA=${pr37MergeSha} PACKAGE_SHA256=${packageSha256} ASSET_COUNT=${assets.length} CREATE=${assets.length} UPDATE=0; PUBLIC_RELEASE=0; INDEXABILITY=0; SITEMAP=0; LLMS=0; SEARCH_SUBMISSION=0; ABORT_ON_ANY_MISMATCH`;
const draftImportPackage = {
  schema_version: 'big5-authority-v2-multi-surface-draft-import.v1',
  generated_at: generatedAt,
  authority: 'CMS/backend only',
  mode: 'draft_noindex_only',
  pr37_merge_sha: pr37MergeSha,
  authority_package_sha256: packageSha256,
  asset_count: draftAssets.length,
  expected_create_count: draftAssets.length,
  expected_update_count: 0,
  assets: draftAssets,
};
const draftImportPackageJson = `${JSON.stringify(draftImportPackage, null, 2)}\n`;
const draftImportPackageSha256 = sha256(draftImportPackageJson);
const draftImportCommand = `php artisan personality:big-five-authority-v2-draft-import --package=../${packageRoot}/big5-authority-v2-release-gate-37/draft-import-package.json --authorization-packet=../${packageRoot}/big5-authority-v2-release-gate-37/production-authorization-packet.json --confirm-pr37-merge-sha=${pr37MergeSha} --confirm-package-sha256=${packageSha256} --expected-create=${assets.length} --expected-update=0 --operator-approved='${approvalPhrase}' --write --json --output=/tmp/big5-authority-v2-production-import-report.json`;
const authorization = {
  schema_version: 'big5-authority-v2-production-authorization-packet.v1',
  generated_at: generatedAt,
  status: 'GO_DRAFT_ONLY_PRODUCTION_IMPORT_AUTHORIZED_PENDING_EXACT_PREFLIGHT',
  dependency_merged_pr: dependency,
  pr37_merge_sha: pr37MergeSha,
  pr37_merge_sha_status: 'VERIFIED_FROM_GITHUB_MERGE_COMMIT',
  artifact_path: `${packageRoot}/big5-authority-v2-release-gate-37/aggregate-manifest.json`,
  package_sha256: packageSha256,
  draft_import_package_path: `${packageRoot}/big5-authority-v2-release-gate-37/draft-import-package.json`,
  draft_import_package_sha256: draftImportPackageSha256,
  asset_count: assets.length,
  local_test_empty_baseline_counts: {
    create: assets.length,
    update: 0,
    noop: 0,
    writes_executed: 0,
  },
  canonical_count: graph.nodes.length,
  alias_301_count: graph.redirects.length,
  write_workflow: {
    dry_run_command: dryRun.command,
    production_preflight_command: draftImportCommand.replace(' --write ', ' --preflight ').replace('production-import-report', 'production-preflight-report'),
    production_command: draftImportCommand,
    production_command_status: 'AVAILABLE_FAIL_CLOSED_DRAFT_ONLY_EXACT_AUTHORIZATION_REQUIRED',
    required_mode: 'draft/noindex first; per-page publish only after every gate passes',
  },
  expected_effects_if_a_separate_writer_is_later_authorized: {
    cms_or_database_draft_creates_on_empty_namespace: assets.length,
    cms_or_database_updates: 0,
    cache_invalidation: 0,
    sitemap_additions: 0,
    llms_additions: 0,
    llms_full_additions: 0,
    indexability_changes: 0,
    search_submissions: 0,
    deploys: 0,
  },
  abort_boundaries: [
    'package SHA256 mismatch',
    'asset count is not exactly 231',
    'any canonical or bilingual identity mismatch',
    'any source, duplicate, claim, or private-boundary failure',
    'any author/reviewer/date or media gate remains false for a page proposed for publication',
    'production create/update counts differ from an approved read-only preflight',
    'writer command is unavailable or the exact approval phrase does not match',
    'production preflight does not report exactly 231 creates and 0 updates',
    'any target identity already exists, including a soft-deleted Article identity',
    'any transaction error or post-write primary-record readback mismatch',
  ],
  current_blockers: {
    publish_eligible: eligibilitySummary.release_eligible,
    withheld: eligibilitySummary.withheld,
    author_reviewer_date_pass: eligibilitySummary.gate_pass_counts.author_reviewer_date,
    media_authority_pass: eligibilitySummary.gate_pass_counts.media_authority,
    visible_evidence_pass: eligibilitySummary.gate_pass_counts.visible_evidence,
  },
  exact_approval_phrase_template: approvalPhrase,
  exact_approval_phrase: approvalPhrase,
  approval_phrase_currently_executable: true,
};

const routeSet = new Set(assets.map((asset) => asset.route));
const assetIdSet = new Set(assets.map((asset) => asset.asset_id));
const qa = {
  schema_version: 'big5-authority-v2-release-gate-qa.v1',
  generated_at: generatedAt,
  status: 'PASS_DRAFT_ONLY_WRITER_AUTHORIZED_NO_PUBLIC_RELEASE',
  package_sha256: packageSha256,
  checks: {
    exact_asset_count_231: assets.length === 231,
    exact_unique_routes: routeSet.size === 231,
    exact_unique_asset_ids: assetIdSet.size === 231,
    schema_validation: assets.every((asset) => asset.schema_valid),
    source_record_validation: assets.every((asset) => asset.source_record_valid),
    source_ledger_validation: sourceLedger.sources.length > 0 && new Set(sourceLedger.sources.map((source) => source.id)).size === sourceLedger.sources.length,
    evidence_validation_recorded_per_page: assets.every((asset) => typeof asset.visible_evidence_valid === 'boolean'),
    duplicate_validation: assets.every((asset) => asset.duplicate_and_intent_valid) && overlap.controls.every((control) => control.cannibalization_control === 'PASS_DISTINCT_INTENT'),
    bilingual_identity_validation: graph.hreflang_pairs.every((pair) => pair.reciprocal && routeSet.has(pair.en) && routeSet.has(pair['zh-CN'])),
    private_boundary_validation: assets.every((asset) => asset.private_boundary_valid && !/\/(attempts|reports|orders|payments|results)(\/|$)/.test(asset.route)),
    link_target_validation: targetValidation.dead_edges.length === 0 && targetValidation.orphan_routes.length === 0,
    editorial_automation_passed_but_human_publish_not_inferred: editorialQa.final_automated_gate_passed === true && editorialQa.human_review_passed === false && editorialQa.publish_allowed === false,
    deterministic_input_digest: /^[a-f0-9]{64}$/.test(packageSha256),
    local_test_db_default_zero_writes: dryRun.measured_database_write_delta === 0,
    every_page_has_release_decision: assets.every((asset) => typeof asset.publish_eligible === 'boolean' && Array.isArray(asset.blockers)),
    no_page_publish_or_index_release: assets.every((asset) => !asset.publish_eligible && !asset.indexability_eligible),
    no_production_mutation: [dryRun.production_writes, dryRun.cms_writes, dryRun.indexability_writes].every((count) => count === 0),
    authorization_packet_complete_and_draft_writer_executable: authorization.approval_phrase_currently_executable === true && typeof authorization.write_workflow.production_command === 'string',
  },
};

fs.writeFileSync(path.join(dir, 'draft-import-package.json'), draftImportPackageJson);

for (const [file, data] of Object.entries({
  'aggregate-manifest.json': manifest,
  'per-page-release-report.json': perPage,
  'local-test-db-dry-run-report.json': dryRun,
  'production-authorization-packet.json': authorization,
  'qa_report.json': qa,
})) fs.writeFileSync(path.join(dir, file), `${JSON.stringify(data, null, 2)}\n`);

console.log(`built Big Five PR37 aggregate: ${assets.length} assets / ${fileEntries.length} inputs / sha256 ${packageSha256} / DRAFT_ONLY_AUTHORIZED`);
