import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const read = (name) => JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8'));

const packageData = read('content-page-draft-package.json');
const evidence = read('public-evidence-index.json');
const qa = read('qa_report.json');
const sourceLedger = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json'), 'utf8'));
const contentPageSource = fs.readFileSync(path.join(root, 'backend/app/Models/ContentPage.php'), 'utf8');

assert(contentPageSource.includes("'methodology'"));
assert(contentPageSource.includes("'trust'"));
for (const field of ['is_public', 'is_indexable', 'review_state', 'claim_gate_status', 'publish_allowed', 'science_review_required', 'operator_approval_required']) assert(contentPageSource.includes(`'${field}'`));

assert.equal(packageData.candidates.length, 4);
assert.deepEqual(new Set(packageData.candidates.map((page) => page.slug)), new Set(['big-five-methodology', 'big-five-source-review-policy']));
assert.deepEqual(new Set(packageData.candidates.map((page) => page.locale)), new Set(['en', 'zh-CN']));
assert.equal(new Set(packageData.candidates.map((page) => `${page.locale}:${page.slug}`)).size, 4);

const unknownKeys = ['product_reliability_coefficients', 'product_validity_coefficients', 'normative_population', 'norm_sample_size', 'percentile_calibration', 'standard_error_of_measurement', 'subgroup_equivalence', 'predictive_accuracy'];
const sourceIds = new Set(sourceLedger.sources.map((source) => source.id));
for (const page of packageData.candidates) {
  assert.equal(page.asset_type, 'ContentPage');
  assert.equal(page.authority_model, 'App\\Models\\ContentPage');
  assert(['methodology', 'trust'].includes(page.page_type));
  assert.equal(page.status, 'draft');
  assert.equal(page.translation_status, 'draft');
  assert.equal(page.review_state, 'science_review');
  assert.equal(page.claim_gate_status, 'not_reviewed');
  assert.equal(page.is_public, false);
  assert.equal(page.is_indexable, false);
  assert.equal(page.publish_allowed, false);
  assert.equal(page.schema_enabled, false);
  assert.equal(page.faq_schema_eligible, false);
  assert.equal(page.science_review_required, true);
  assert.equal(page.operator_approval_required, true);
  assert.equal(page.owner, null);
  assert.equal(page.reviewer, null);
  assert.equal(page.published_at, null);
  assert.equal(page.last_reviewed_at, null);
  assert.equal(page.effective_at, null);
  assert.equal(page.cms_write_executed, false);
  assert.deepEqual(Object.keys(page.product_evidence_unknowns), unknownKeys);
  assert(Object.values(page.product_evidence_unknowns).every((value) => value === 'Unknown'));
  assert(page.evidence_source_ids.every((sourceId) => sourceIds.has(sourceId)));
  for (const heading of ['privacy', 'change_history', 'evidence']) assert(page.headings_json.some((item) => item.key === heading));
  assert(page.content_md.length >= 1500);
}

assert.equal(evidence.sources.length, 6);
assert.equal(new Set(evidence.sources.map((source) => source.source_id)).size, 6);
assert(evidence.sources.every((source) => sourceIds.has(source.source_id)));
assert(evidence.sources.every((source) => source.public_url.startsWith('https://')));
assert(evidence.sources.every((source) => source.limitation.length >= 30));

assert.equal(qa.status, 'PASS_PENDING_SCIENCE_REVIEW');
assert.deepEqual(qa.counts, { page_identities: 2, locales: 2, content_page_candidates: 4, evidence_sources: 6 });
assert.equal(qa.checks.uses_existing_content_page_model, true);
assert.equal(qa.checks.parallel_cms_stack_created, false);
assert.equal(qa.checks.all_numeric_product_evidence_unknown, true);
assert.equal(qa.checks.all_non_public_non_indexable_drafts, true);
assert.equal(qa.checks.attribution_synthesized, 0);
assert.equal(qa.checks.cms_writes, 0);
assert.equal(qa.checks.production_changes, 0);

const serialized = JSON.stringify({ packageData, evidence }).toLowerCase();
for (const forbidden of ['clinically validated', 'most accurate', 'guaranteed result', '/attempt/', '/report/', '/orders/']) assert(!serialized.includes(forbidden));

console.log('Big Five technical trust validation passed: 4 ContentPage drafts, public evidence index, all product metrics Unknown');
