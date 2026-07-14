import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const read = (name) => JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8'));
const matrix = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-article-ia-21/article-intent-matrix.json'), 'utf8'));
const ledger = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json'), 'utf8'));
const raw = read('raw-drafts.json');
const reviews = read('skeptical-review.json');
const repaired = read('repaired-drafts.json');
const finalPackage = read('final-package.json');
const mappings = read('source-mapping.json');
const qa = read('qa_report.json');

const locked = matrix.themes.filter((theme) => theme.batch === 25);
const expectedPairs = new Set(locked.flatMap((theme) => theme.locales.map((locale) => `${theme.topic_id}:${locale.locale}:${locale.slug}`)));
const actualPairs = new Set(finalPackage.assets.map((asset) => `${asset.topic_id}:${asset.locale}:${asset.slug}`));
assert.equal(locked.length, 5);
assert.deepEqual(actualPairs, expectedPairs);
assert.equal(finalPackage.assets.length, 10);
assert.equal(finalPackage.assets.filter((asset) => asset.locale === 'en').length, 5);
assert.equal(finalPackage.assets.filter((asset) => asset.locale === 'zh-CN').length, 5);
assert(finalPackage.assets.every((asset) => asset.batch === 25));
assert.equal(new Set(finalPackage.assets.map((asset) => asset.unique_intent_key)).size, 5);

const sourceIds = new Set(ledger.sources.map((source) => source.id));
const requiredSections = ['direct_answer', 'evidence', 'nuance_counterexample', 'concrete_scenario', 'practical_framework', 'limitation', 'visible_sources', 'method_product_boundary', 'internal_links'];
for (const asset of finalPackage.assets) {
  const lockedTheme = locked.find((theme) => theme.topic_id === asset.topic_id);
  const lockedLocale = lockedTheme.locales.find((locale) => locale.locale === asset.locale);
  assert.equal(asset.slug, lockedLocale.slug);
  assert.equal(asset.path, lockedLocale.path);
  assert.equal(asset.title_intent, lockedLocale.title_intent);
  assert.equal(asset.primary_question, lockedLocale.primary_question);
  assert.equal(asset.audience, lockedLocale.audience);
  assert.equal(asset.user_task, lockedLocale.user_task);
  assert.deepEqual(asset.keywords, lockedLocale.keywords);
  assert.deepEqual(asset.internal_link_targets, lockedLocale.internal_link_targets);
  assert.deepEqual(asset.source_mapping.map((source) => source.source_id), lockedLocale.source_requirements);
  assert(asset.source_mapping.every((source) => sourceIds.has(source.source_id)));
  assert(asset.source_mapping.every((source) => source.public_url || source.repository_path));
  assert.deepEqual(asset.sections.map((section) => section.key), requiredSections);
  assert(asset.sections.every((section) => section.body_md.length >= 30));
  assert(asset.sections.find((section) => section.key === 'method_product_boundary').body_md.includes('Unknown'));
  assert.equal(asset.review_status, 'pending_manual_review');
  assert.equal(asset.reviewer, null);
  assert.equal(asset.author, null);
  assert.equal(asset.published_at, null);
  assert.equal(asset.cms_write_executed, false);
  assert.equal(asset.publish_state_change, false);
  assert.equal(asset.indexability_change, false);
}

assert.equal(raw.assets.length, 10);
assert.equal(reviews.reviews.length, 10);
assert.equal(repaired.assets.length, 10);
assert.equal(mappings.mappings.length, 10);
assert.deepEqual(new Set(raw.assets.map((asset) => `${asset.topic_id}:${asset.locale}:${asset.slug}`)), expectedPairs);
assert.deepEqual(new Set(repaired.assets.map((asset) => `${asset.topic_id}:${asset.locale}:${asset.slug}`)), expectedPairs);
assert(reviews.reviews.every((review) => review.repair_required === true && review.reviewer === null));
assert(repaired.assets.every((asset) => asset.review_status === 'repaired_pending_manual_review'));

assert.equal(qa.status, 'PASS_PENDING_MANUAL_REVIEW');
assert.deepEqual(qa.counts, { locked_themes: 5, article_assets: 10, en_assets: 5, zh_cn_assets: 5 });
assert(Object.values(qa.checks).every((value) => value === true || value === 0));

const serialized = JSON.stringify({ finalPackage, reviews }).toLowerCase();
for (const forbidden of ['/attempt/', '/report/', '/orders/', 'clinically validated', 'guaranteed accurate', 'recommended retest interval is']) assert(!serialized.includes(forbidden));

console.log('Big Five batch 25 validation passed: exact 5 locked themes / 10 bilingual Article candidates / full review chain');
