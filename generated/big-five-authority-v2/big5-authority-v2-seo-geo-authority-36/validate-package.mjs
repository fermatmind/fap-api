import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const read = (file) => JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'));
const contract = read('authority-contract.json');
const packageData = read('candidate-eligibility.json');
const summary = read('eligibility-summary.json');
const qa = read('qa_report.json');
const candidates = packageData.candidates;
const fail = (message) => { throw new Error(message); };

if (contract.schema_version !== 'big5-seo-geo-authority-contract.v1') fail('invalid authority contract schema');
if (packageData.schema_version !== 'big5-seo-geo-candidate-eligibility.v1') fail('invalid candidate schema');
if (candidates.length !== 231 || new Set(candidates.map((row) => row.route)).size !== 231) fail('candidate inventory must contain 231 unique routes');
if (!contract.rules.per_candidate_decision_required || !contract.rules.batch_auto_index_forbidden) fail('per-candidate fail-closed rules are required');
if (!contract.rules.schema_requires_matching_visible_content || !contract.rules.json_ld_is_not_graph_or_citation_proof) fail('visible schema and claim boundaries are required');
if (Object.values(contract.mutations).some((count) => count !== 0)) fail('PR36 mutations must remain zero');

for (const row of candidates) {
  if (row.authority !== 'CMS/backend_candidate') fail(`invalid authority: ${row.route}`);
  if (row.metadata_candidate.canonical_path !== row.route || !row.gates.canonical_consistent) fail(`canonical mismatch: ${row.route}`);
  if (!row.gates.hreflang_real_and_consistent) fail(`hreflang mismatch: ${row.route}`);
  if (row.metadata_candidate.hreflang.status === 'real_reciprocal_pair') {
    const alternates = row.metadata_candidate.hreflang.alternates;
    if (!alternates.en || !alternates['zh-CN'] || !alternates['x-default']) fail(`incomplete hreflang: ${row.route}`);
  }
  if (row.gates.author_reviewer_date || row.gates.media_authority) fail(`unverified review or media gate passed: ${row.route}`);
  if (row.release_eligible || row.blocking_gates.length === 0) fail(`candidate must remain withheld: ${row.route}`);
  if (row.projections.metadata_publish_eligible || row.projections.schema_eligible || row.projections.sitemap_eligible || row.projections.llms_eligible || row.projections.llms_full_eligible) fail(`public eligibility leaked: ${row.route}`);
  if (row.projections.robots !== 'noindex,nofollow' || row.projections.schema_payload !== null || row.projections.public_release_executed) fail(`release projection leaked: ${row.route}`);
  if (row.projections.schema_eligible && (!row.gates.visible_evidence || !row.gates.claim_boundary)) fail(`schema lacks visible evidence: ${row.route}`);
  if (!row.gates.private_boundary) fail(`private route leaked: ${row.route}`);
}

if (summary.candidate_count !== 231 || summary.release_eligible !== 0 || summary.withheld !== 231) fail('summary release counts mismatch');
for (const key of ['metadata_publish_eligible', 'schema_eligible', 'sitemap_eligible', 'llms_eligible', 'llms_full_eligible', 'robots_index_follow']) {
  if (summary[key] !== 0) fail(`${key} must be zero`);
}
if (summary.robots_noindex_nofollow !== 231) fail('all candidates must remain noindex,nofollow');
if (qa.status !== 'PASS_FAIL_CLOSED_ZERO_RELEASE' || Object.values(qa.checks).some((value) => value !== true)) fail('QA report failed');

console.log(`Big Five PR36 validation passed: ${summary.candidate_count} individually gated / ${summary.withheld} withheld / zero metadata-schema-sitemap-llms-index releases`);
