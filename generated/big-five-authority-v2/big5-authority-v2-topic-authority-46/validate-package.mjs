import assert from 'node:assert/strict';
import crypto from 'node:crypto';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const read = (name) => JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8'));
const canonicalize = (value) => {
  if (Array.isArray(value)) return value.map(canonicalize);
  if (value && typeof value === 'object') return Object.fromEntries(Object.keys(value).sort().map((key) => [key, canonicalize(value[key])]));
  return value;
};
const canonicalSha256 = (value) => crypto.createHash('sha256').update(JSON.stringify(canonicalize(value))).digest('hex');
const sha256File = (relative) => crypto.createHash('sha256').update(fs.readFileSync(path.join(root, relative))).digest('hex');

const payload = read('topic-draft-revision-package.json');
const dryRun = read('dry-run-report.json');
const recordedHash = fs.readFileSync(path.join(dir, 'topic-draft-revision-package.sha256'), 'utf8').trim();

assert.equal(payload.schema_version, 'big5-topic-authority-draft-revision.v1');
assert.equal(payload.mode, 'backend_authoritative_working_revision_candidates_zero_write');
assert.equal(payload.topic_count, 2);
assert.equal(payload.topics.length, 2);
assert.deepEqual(payload.topics.map((topic) => topic.locale).sort(), ['en', 'zh-CN']);
assert.deepEqual(payload.topics.map((topic) => topic.route).sort(), ['/en/topics/big-five', '/zh/topics/big-five']);
assert.equal(new Set(payload.topics.map((topic) => topic.asset_id)).size, 2);
assert.equal(recordedHash, canonicalSha256(payload));

for (const source of Object.values(payload.source_inventory)) {
  assert.equal(source.sha256, sha256File(source.path));
}

const visibleStrings = [];
for (const topic of payload.topics) {
  assert.deepEqual(topic.identity, { org_id: 0, topic_code: 'big-five', slug: 'big-five', locale: topic.locale });
  assert.equal(topic.revision_contract.target_resolution, 'existing_identity_or_block');
  assert.equal(topic.revision_contract.revision_operation, 'create_isolated_working_revision');
  assert.equal(topic.revision_contract.workflow_state, 'draft_pending_manual_review');
  assert.equal(topic.revision_contract.public_reader_selects_working_revision, false);
  assert.equal(topic.revision_contract.promotion_authorized, false);

  const { profile, sections, entries, seo_meta: seo, authority } = topic.snapshot;
  assert.equal(profile.status, 'draft');
  assert.equal(profile.is_public, false);
  assert.equal(profile.is_indexable, false);
  assert.equal(profile.cover_image_url, null);
  assert.equal(profile.published_at, null);
  assert.deepEqual(sections.map((section) => section.section_key), ['overview', 'key_concepts', 'why_it_matters', 'who_should_read']);
  assert.equal(entries.length, 1);
  assert.equal(entries[0].entry_type, 'scale');
  assert.equal(entries[0].group_key, 'tests');
  assert.equal(entries[0].target_key, 'BIG5_OCEAN');
  assert.equal(entries[0].target_url_override, null);
  assert.equal(entries[0].payload_json.canonical_authority, 'scales_registry.primary_slug');
  assert.equal(entries[0].payload_json.expected_canonical_path, topic.locale === 'en'
    ? '/en/tests/big-five-personality-test-ocean-model'
    : '/zh/tests/big-five-personality-test-ocean-model');
  assert.equal(seo.canonical_url, topic.route);
  assert.equal(seo.robots, 'noindex,follow');
  assert.equal(seo.jsonld_overrides_json, null);

  assert.equal(authority.claim_mode, 'supplementary_explanation_only');
  assert.equal(authority.recommendation_authority, false);
  assert.equal(authority.diagnostic_authority, false);
  assert.equal(authority.outcome_prediction_authority, false);
  assert.equal(authority.visible_provenance.author, null);
  assert.equal(authority.visible_provenance.reviewer, null);
  assert.deepEqual(authority.visible_provenance.sources.map((source) => source.source_id), [
    'academic.goldberg-1990-big-five-structure',
    'academic.soto-john-2017-bfi2',
  ]);
  assert(Object.values(authority.visible_dates).filter((value) => value === null).length === 3);
  assert.deepEqual(authority.visible_dates.forbidden_fallbacks, ['revision_created_at', 'imported_at', 'built_at', 'deployed_at', 'model_created_at', 'model_updated_at']);
  assert.equal(authority.media.mapping_status, 'missing_pending');
  assert.equal(authority.media.media_eligible, false);
  assert.equal(authority.media.operator_approval_claimed, false);
  assert.deepEqual(authority.media.slots.map((slot) => slot.slot), ['hero', 'inline', 'og']);
  for (const slot of authority.media.slots) {
    assert.equal(slot.status, 'missing_pending');
    for (const field of ['media_asset_id', 'media_asset_key', 'variant_key', 'public_url', 'alt', 'rights', 'license', 'provenance', 'operator_approval_ref']) assert.equal(slot[field], null);
  }
  assert(Object.values(topic.gates).every((value) => value === false));
  assert(topic.blockers.includes('manual_review_missing'));
  assert(topic.blockers.includes('approved_media_missing'));

  visibleStrings.push(
    profile.title,
    profile.subtitle,
    profile.excerpt,
    profile.hero_kicker,
    ...sections.flatMap((section) => [section.title, section.body_md]),
    ...entries.flatMap((entry) => [entry.title_override, entry.excerpt_override, entry.badge_label, entry.cta_label]),
    seo.seo_title,
    seo.seo_description,
  );
}

const publicCopy = visibleStrings.filter(Boolean).join('\n').toLowerCase();
for (const forbidden of [
  'seo cluster',
  'seo clusters',
  'trait-based recommendation',
  'trait-by-trait recommendation',
  'career recommendation',
  'career matcher',
  '职业推荐',
  '职业匹配',
  '特质推荐',
  'seo 主题簇',
]) assert(!publicCopy.includes(forbidden), `public copy contains forbidden phrase: ${forbidden}`);
assert(!publicCopy.includes('mbti'));

assert(Object.entries(payload.actions).every(([key, value]) => key === 'database_reads' || value === 0));
assert.equal(payload.actions.database_reads, 0);
assert.equal(dryRun.package_sha256, recordedHash);
assert.equal(dryRun.status, 'PASS_DRAFT_REVISION_PACKAGE_BLOCKED_FOR_PROMOTION');
assert.deepEqual(dryRun.counts, { topic_candidates: 2, working_revision_candidates: 2, promotion_eligible: 0, blocked: 2 });
assert.deepEqual(dryRun.actions, payload.actions);

console.log(`Big Five PR46 validation passed: ${recordedHash} / 2 Topic revision candidates / 0 writes`);
