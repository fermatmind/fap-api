import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const read = (name) => JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8'));
const canonicalize = (value) => {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]));
  return value;
};
const canonicalSha256 = (value) => crypto.createHash('sha256').update(JSON.stringify(canonicalize(value))).digest('hex');

const intake = read('approved-media-intake.json');
const schema = read('approved-media-intake.schema.json');
const mappingPackage = read('mapping-package.json');
const report = read('preflight-report.json');
const recordedHash = fs.readFileSync(path.join(dir, 'mapping-package.sha256'), 'utf8').trim();

assert.equal(intake.schema_version, 'big5-approved-media-intake.v1');
assert.equal(intake.operator_approval_claimed, false);
assert.deepEqual(intake.approved_assets, []);
assert.equal(schema.properties.approved_assets.maxItems, 54);
assert.deepEqual(schema.properties.approved_assets.items.properties.slot.enum, ['hero', 'inline', 'og']);
for (const field of ['media_asset_id', 'media_asset_key', 'variant_key', 'public_url', 'alt', 'rights', 'license', 'provenance', 'operator_approval_ref', 'content_identity']) {
  assert(schema.properties.approved_assets.items.required.includes(field));
}

assert.equal(mappingPackage.schema_version, 'big5-media-authority-mapping-package.v1');
assert.equal(mappingPackage.mappings.length, 231);
assert.equal(new Set(mappingPackage.mappings.map((row) => row.candidate_key)).size, 231);
assert.equal(new Set(mappingPackage.mappings.map((row) => row.route)).size, 231);
assert.deepEqual(mappingPackage.counts, {
  candidate_pages: 231,
  family_locale_requirement_groups: 18,
  grouped_slot_requirements: 54,
  approved_grouped_slot_requirements: 0,
  pending_grouped_slot_requirements: 54,
  total_page_slots: 693,
  mapped_page_slots: 0,
  missing_pending_page_slots: 693,
});
for (const mapping of mappingPackage.mappings) {
  assert.equal(mapping.mapping_status, 'missing_pending');
  assert.deepEqual(mapping.slots.map((slot) => slot.slot), ['hero', 'inline', 'og']);
  for (const slot of mapping.slots) {
    assert.equal(slot.status, 'missing_pending');
    assert.equal(slot.content_identity, `big5:${mapping.page_family}:${mapping.locale}:${slot.slot}`);
    for (const field of ['media_asset_id', 'media_asset_key', 'variant_key', 'public_url', 'alt', 'rights', 'license', 'provenance', 'operator_approval_ref']) assert.equal(slot[field], null);
  }
  assert.equal(mapping.cms_write_executed, false);
  assert.equal(mapping.media_upload_executed, false);
  assert.equal(mapping.publish_state_change, false);
  assert.equal(mapping.indexability_change, false);
}
assert(Object.entries(mappingPackage.actions).every(([key, value]) => key === 'database_reads' || value === 0));
assert.equal(mappingPackage.actions.database_reads, 0);
assert.equal(recordedHash, canonicalSha256(mappingPackage));
assert.equal(report.mapping_package_sha256, recordedHash);
assert.equal(report.status, 'PASS_FAIL_CLOSED_NO_APPROVED_ASSETS');
assert.equal(report.mode, 'preflight_only_zero_write');
assert.deepEqual(report.counts, mappingPackage.counts);
assert.deepEqual(report.actions, mappingPackage.actions);

const serialized = JSON.stringify({ intake, mappingPackage }).toLowerCase();
for (const forbidden of ['mbti.desktop_clone', 'personality.type_icon', '/attempt/', '/report/', '/orders/']) assert(!serialized.includes(forbidden));
assert(!mappingPackage.mappings.some((mapping) => mapping.slots.some((slot) => typeof slot.public_url === 'string')));

console.log(`Big Five PR41 validation passed: ${recordedHash} / 231 pages / 693 missing_pending slots / 0 writes`);
