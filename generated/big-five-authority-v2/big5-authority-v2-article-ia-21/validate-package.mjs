import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const read = (name) => JSON.parse(fs.readFileSync(path.join(dir, name), 'utf8'));

const audit = read('existing-surface-audit.json');
const matrix = read('article-intent-matrix.json');
const evidence = read('evidence-register.json');
const handoff = read('batch-handoff.json');
const qa = read('qa_report.json');
const sourceLedger = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-source-ledger-05/source-ledger.json'), 'utf8'));

assert.deepEqual(audit.counts, { articles: 9, topic_hubs: 2, total: 11 });
assert.equal(audit.surfaces.length, 11);
assert.equal(audit.surfaces.filter((surface) => surface.surface_type === 'article').length, 9);
assert.equal(audit.surfaces.filter((surface) => surface.surface_type === 'topic_hub').length, 2);
assert.deepEqual(
  new Set(audit.surfaces.filter((surface) => surface.surface_type === 'topic_hub').map((surface) => surface.identity)),
  new Set(['/en/topics/big-five', '/zh/topics/big-five']),
);
assert(audit.surfaces.every((surface) => surface.authority === 'CMS/backend'));
assert(audit.surfaces.every((surface) => surface.publication_or_indexability_change === false));

assert.deepEqual(matrix.counts, { batches: 10, themes: 50, locale_drafts: 100 });
assert.equal(matrix.themes.length, 50);
assert.deepEqual([...new Set(matrix.themes.map((theme) => theme.batch))], [24, 25, 26, 27, 28, 29, 30, 31, 32, 33]);
for (const batch of [24, 25, 26, 27, 28, 29, 30, 31, 32, 33]) {
  assert.equal(matrix.themes.filter((theme) => theme.batch === batch).length, 5, `batch ${batch} must contain five themes`);
}
assert.equal(new Set(matrix.themes.map((theme) => theme.topic_id)).size, 50);
assert.equal(new Set(matrix.themes.map((theme) => theme.unique_intent_key)).size, 50);
assert.equal(new Set(matrix.themes.map((theme) => theme.locked_slug)).size, 50);

const sourceIds = new Set(sourceLedger.sources.map((source) => source.id));
const fields = ['title_intent', 'primary_question', 'audience', 'user_task', 'keywords', 'search_intent', 'internal_link_targets', 'source_requirements', 'risk_boundary'];
for (const theme of matrix.themes) {
  assert.match(theme.topic_id, new RegExp(`^big5\\.article\\.${theme.batch}\\.`));
  assert.equal(theme.locales.length, 2);
  assert.deepEqual(theme.locales.map((locale) => locale.locale).sort(), ['en', 'zh-CN']);
  for (const locale of theme.locales) {
    for (const field of fields) assert(locale[field] && locale[field].length > 0, `${theme.topic_id}/${locale.locale} missing ${field}`);
    assert.equal(locale.slug, theme.locked_slug);
    assert.equal(locale.publication_state, 'draft_candidate_only');
    assert.equal(locale.indexability_state, 'unchanged');
    assert(locale.path.endsWith(`/articles/${theme.locked_slug}`));
    const prefix = locale.locale === 'en' ? '/en/' : '/zh/';
    assert(locale.internal_link_targets.every((target) => target.startsWith(prefix)));
    assert(locale.source_requirements.every((sourceId) => sourceIds.has(sourceId)), `${theme.topic_id} uses unlocked evidence`);
  }
}

assert.equal(evidence.academic_evidence.status, 'AVAILABLE_FROM_LOCKED_PR05_LEDGER');
assert.equal(evidence.competitor_evidence.status, 'AVAILABLE_AS_TIME_BOUND_STRUCTURE_ONLY');
assert.equal(evidence.gsc_evidence.status, 'GSC_EVIDENCE_PENDING');
assert.equal(evidence.gsc_evidence.permitted_inference, false);
assert(evidence.academic_evidence.source_ids.every((sourceId) => sourceIds.has(sourceId)));
assert(evidence.competitor_evidence.source_ids.every((sourceId) => sourceIds.has(sourceId)));

assert.equal(handoff.total_batches, 10);
assert.equal(handoff.batches.length, 10);
for (const batch of handoff.batches) {
  assert.equal(batch.topic_ids.length, 5);
  assert.equal(batch.required_theme_count, 5);
  assert.equal(batch.required_locale_draft_count, 10);
  assert.equal(batch.mutation_rule, 'consume_locked_matrix_only');
  assert.deepEqual(batch.topic_ids, matrix.themes.filter((theme) => theme.batch === batch.batch).map((theme) => theme.topic_id));
}

assert.equal(qa.status, 'PASS');
assert.equal(qa.checks.unique_topic_ids, 50);
assert.equal(qa.checks.unique_intents, 50);
assert.equal(qa.checks.unique_slugs, 50);
assert.equal(qa.checks.each_theme_has_en_zh_pair, true);
assert.equal(qa.checks.gsc_status, 'GSC_EVIDENCE_PENDING');
assert.equal(qa.checks.body_assets_generated, 0);
assert.equal(qa.checks.cms_writes, 0);
assert.equal(qa.checks.publication_or_indexability_changes, 0);
assert.equal(qa.checks.trait_combination_matrices, 0);

const serialized = JSON.stringify({ audit, matrix, evidence, handoff, qa }).toLowerCase();
assert(!serialized.includes('/attempt'));
assert(!serialized.includes('/report/'));
assert(!serialized.includes('/orders/'));
assert(matrix.themes.every((theme) => !Object.hasOwn(theme, 'career_combinations')));
assert(matrix.themes.every((theme) => !Object.hasOwn(theme, 'problem_combinations')));

console.log('Big Five article IA package validation passed: 11 existing surfaces, 10 batches, 50 themes, 100 paired drafts, GSC pending');
