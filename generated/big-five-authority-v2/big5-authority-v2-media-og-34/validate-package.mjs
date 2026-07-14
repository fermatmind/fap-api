import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const read = (file) => JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'));
const audit = read('media-authority-audit.json');
const map = read('candidate-media-map.json');
const upload = read('upload-mapping-manifest.json');
const dryRun = read('dry-run-report.json');
const qa = read('qa_report.json');
const familyCounts = { article: 109, domain: 10, facet: 60, facet_hub: 2, model_hub: 2, range: 40, technical_trust: 4, test_landing: 2, topic_hub: 2 };

assert.equal(audit.audit_mode, 'read_only_repository_authority');
assert.equal(audit.production_runtime_media_inventory, 'Unknown_not_queried');
assert.equal(audit.eligibility_gates.length, 7);
assert.deepEqual(audit.baseline_summary.map((row) => row.asset_count), [6, 224, 16]);
assert.deepEqual(audit.baseline_summary.map((row) => row.eligible_big_five_count), [0, 0, 0]);
assert.deepEqual(audit.totals, {
  audited_repository_baseline_assets: 246,
  published_public_baseline_assets: 240,
  explicit_operator_approval_evidence_assets: 16,
  big_five_semantic_matches: 0,
  eligible_big_five_assets: 0,
});
assert.equal(audit.exclusions.reduce((sum, row) => sum + row.count, 0), 246);
assert.equal(audit.decision, 'NO_APPROVED_BIG_FIVE_MEDIA_FOUND_FAIL_CLOSED');

assert.equal(map.mappings.length, 231);
assert.equal(new Set(map.mappings.map((mapping) => mapping.candidate_key)).size, 231);
assert.equal(new Set(map.mappings.map((mapping) => mapping.route)).size, 231);
assert(map.mappings.every((mapping) => mapping.route.startsWith(`/${mapping.locale === 'en' ? 'en' : 'zh'}/`)));
assert.deepEqual(Object.fromEntries(Object.keys(familyCounts).map((family) => [family, map.mappings.filter((mapping) => mapping.page_family === family).length])), familyCounts);
assert.deepEqual({ en: map.mappings.filter((mapping) => mapping.locale === 'en').length, 'zh-CN': map.mappings.filter((mapping) => mapping.locale === 'zh-CN').length }, { en: 119, 'zh-CN': 112 });
for (const mapping of map.mappings) {
  assert.equal(mapping.mapping_status, 'missing_pending');
  assert.deepEqual(mapping.slots.map((slot) => slot.slot), ['hero', 'inline', 'og']);
  for (const slot of mapping.slots) {
    assert.equal(slot.status, 'missing_pending');
    assert.equal(slot.media_asset_key, null);
    assert.equal(slot.variant_key, null);
    assert.equal(slot.public_url, null);
    assert.equal(slot.alt, null);
    assert.equal(slot.rights, null);
    assert.equal(slot.provenance, null);
    assert.equal(slot.operator_approval_ref, null);
  }
  assert.equal(mapping.cms_write_executed, false);
  assert.equal(mapping.media_upload_executed, false);
  assert.equal(mapping.publish_state_change, false);
  assert.equal(mapping.indexability_change, false);
}

assert.equal(upload.status, 'PLANNING_ONLY_PENDING_OPERATOR_MEDIA');
assert.equal(upload.requirements.length, 18);
assert.equal(upload.requirements.reduce((sum, row) => sum + row.candidate_count, 0), 231);
assert.equal(upload.requirements.reduce((sum, row) => sum + row.slot_requirements.length, 0), 54);
assert(upload.requirements.every((row) => row.status === 'pending_operator_media'));
assert(upload.requirements.every((row) => row.slot_requirements.every((slot) => slot.media_asset_key === null && slot.public_url === null && slot.operator_approval_required === true)));
assert.equal(upload.upload_executed, false);
assert.equal(upload.mapping_write_executed, false);
assert.equal(upload.fabricated_urls, 0);

assert.deepEqual(dryRun.counts, {
  candidate_pages: 231,
  unique_routes: 231,
  candidate_mapping_rows: 231,
  total_slots: 693,
  mapped_slots: 0,
  missing_pending_slots: 693,
  approved_big_five_media_assets: 0,
  family_locale_requirement_groups: 18,
  pending_grouped_slot_requirements: 54,
});
assert.deepEqual(dryRun.family_counts, familyCounts);
assert.deepEqual(dryRun.locale_counts, { en: 119, 'zh-CN': 112 });
assert(Object.values(dryRun.actions).every((count) => count === 0));
assert.equal(qa.status, 'PASS_PENDING_OPERATOR_MEDIA');
assert.deepEqual(qa.counts, dryRun.counts);
assert(Object.values(qa.checks).every((value) => value === true));
const serializedMappings = JSON.stringify({ map, upload }).toLowerCase();
for (const forbidden of ['https://', 'http://', '/attempt/', '/report/', '/orders/', 'mbti.desktop_clone', 'personality.type_icon']) assert(!serializedMappings.includes(forbidden));

console.log('Big Five PR34 validation passed: 231 candidates / 693 fail-closed media slots / 0 approved mappings / 0 writes');
