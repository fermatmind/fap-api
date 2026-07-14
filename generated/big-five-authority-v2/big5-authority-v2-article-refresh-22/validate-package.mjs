import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const read = (name) => JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8'));

const articles = read('article-refresh-candidates.json');
const hubs = read('topic-hub-candidates.json');
const reviews = read('skeptical-review.json');
const qa = read('qa_report.json');
const iaAudit = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-article-ia-21/existing-surface-audit.json'), 'utf8'));
const iaMatrix = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-article-ia-21/article-intent-matrix.json'), 'utf8'));
const sourceLedger = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json'), 'utf8'));

const expectedArticles = new Set(iaAudit.surfaces.filter((item) => item.surface_type === 'article').map((item) => `${item.locale}:${item.identity}`));
const actualArticles = new Set(articles.candidates.map((item) => `${item.locale}:${item.slug}`));
assert.deepEqual(actualArticles, expectedArticles);
assert.equal(articles.candidates.length, 9);
assert.equal(articles.candidates.filter((item) => item.locale === 'en').length, 3);
assert.equal(articles.candidates.filter((item) => item.locale === 'zh-CN').length, 6);

const expectedHubs = new Set(iaAudit.surfaces.filter((item) => item.surface_type === 'topic_hub').map((item) => item.identity));
assert.deepEqual(new Set(hubs.candidates.map((item) => item.route)), expectedHubs);
assert.equal(hubs.candidates.length, 2);

const requiredSections = ['direct_opening', 'logic', 'example', 'counterexample', 'action_framework', 'boundary', 'sources', 'next_steps'];
const sourceIds = new Set(sourceLedger.sources.map((item) => item.id));
const pr24to33Slugs = new Set(iaMatrix.themes.map((theme) => theme.locked_slug));
for (const article of articles.candidates) {
  assert.equal(article.review_status, 'pending_manual_review');
  assert.equal(article.cms_authority.author, null);
  assert.equal(article.cms_authority.reviewer, null);
  assert.equal(article.cms_authority.published_at, null);
  assert.equal(article.cms_authority.updated_at, null);
  assert.equal(article.cms_authority.preserve_existing_record_identity, true);
  assert(!article.title.includes('FermatMind'));
  assert(!article.title.includes('费马测试'));
  assert.deepEqual(article.sections.map((section) => section.key), requiredSections);
  assert(article.sections.every((section) => section.body_md.length >= 40), `${article.locale}:${article.slug} has a thin section`);
  assert(article.source_mapping.length >= 1);
  assert(article.source_mapping.every((source) => sourceIds.has(source.source_id)));
  assert(article.source_mapping.every((source) => source.public_url.startsWith('https://doi.org/')));
  assert(article.source_mapping.every((source) => source.limitation.length >= 20));
  assert(article.internal_link_targets.length >= 3);
  assert(article.internal_link_targets.every((target) => target.startsWith(article.locale === 'en' ? '/en/' : '/zh/')));
  assert.equal(pr24to33Slugs.has(article.slug), false, `${article.slug} belongs to PR24-33, not PR22`);
  assert.equal(article.cms_write_executed, false);
  assert.equal(article.publish_state_change, false);
  assert.equal(article.indexability_change, false);
}

for (const hub of hubs.candidates) {
  assert.equal(hub.review_status, 'pending_manual_review');
  assert.equal(hub.enumeration.source, 'backend_public_api');
  assert.deepEqual(hub.enumeration.required_states, ['published', 'eligible']);
  assert.deepEqual(hub.enumeration.hardcoded_entries, []);
  assert(hub.enumeration.exclude.includes('pending_manual_review'));
  assert.equal(hub.cms_write_executed, false);
  assert.equal(hub.publish_state_change, false);
  assert.equal(hub.indexability_change, false);
}

assert.equal(reviews.reviews.length, 9);
assert(reviews.reviews.every((review) => review.final_status === 'pending_manual_review'));
assert(reviews.reviews.every((review) => review.unresolved.includes('Human editorial review has not occurred.')));
assert.equal(qa.status, 'PASS_PENDING_MANUAL_REVIEW');
assert.deepEqual(qa.counts, { article_candidates: 9, en_articles: 3, zh_cn_articles: 6, topic_hubs: 2, total_surfaces: 11 });
assert.equal(qa.checks.cms_attribution_values_synthesized, 0);
assert.equal(qa.checks.topic_hub_hardcoded_entries, 0);
assert.equal(qa.checks.cms_writes, 0);
assert.equal(qa.checks.publication_changes, 0);
assert.equal(qa.checks.indexability_changes, 0);
assert.equal(qa.checks.pr24_33_articles_added, 0);

const serialized = JSON.stringify({ articles, hubs, reviews }).toLowerCase();
for (const forbidden of ['/attempt', '/report/', '/orders/', 'guaranteed accurate', 'clinically validated']) assert(!serialized.includes(forbidden));

console.log('Big Five article refresh validation passed: exact 9 articles + 2 backend-enumerated hubs; all pending manual review');
