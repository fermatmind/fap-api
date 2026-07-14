import assert from 'node:assert/strict';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const dir = path.dirname(fileURLToPath(import.meta.url));
const root = path.resolve(dir, '../../..');
const read = (file) => JSON.parse(fs.readFileSync(path.join(dir, file), 'utf8'));
const graph = read('link-graph.json');
const overlap = read('intent-overlap-report.json');
const targets = read('target-validation-report.json');
const qa = read('qa_report.json');
const inventory = JSON.parse(fs.readFileSync(path.join(root, 'generated/big-five-authority-v2/big5-authority-v2-media-og-34/candidate-media-map.json'), 'utf8'));
const routes = new Set(graph.nodes.map((node) => node.route));
const inventoryRoutes = new Set(inventory.mappings.map((mapping) => mapping.route));

assert.equal(graph.schema_version, 'big5-authority-v2-link-graph.v1');
assert.equal(graph.authority, 'PR07-34 candidate inventory + CMS/backend');
assert.equal(graph.release_effect, 'none_planning_and_validation_only');
assert.equal(graph.nodes.length, 231);
assert.equal(routes.size, 231);
assert.deepEqual(routes, inventoryRoutes);
assert(graph.nodes.every((node) => node.canonical_path === node.route && node.canonical_mode === 'self_canonical_candidate'));
assert(graph.nodes.every((node) => node.authority === 'CMS/backend_candidate' && node.release_eligibility === 'deferred_to_pr36'));
assert.equal(graph.nodes.filter((node) => node.intent_class === 'legacy_polarity_explainer').length, 10);
assert(graph.nodes.filter((node) => node.intent_class === 'legacy_polarity_explainer').every((node) => node.locale === 'en' && node.navigation_visibility === 'compatibility_only'));

assert.equal(graph.edges.length, 1199);
assert.equal(new Set(graph.edges.map((edge) => `${edge.source}>${edge.target}`)).size, graph.edges.length);
for (const edge of graph.edges) {
  assert(routes.has(edge.source));
  assert(routes.has(edge.target));
  assert.notEqual(edge.source, edge.target);
  const source = graph.nodes.find((node) => node.route === edge.source);
  const target = graph.nodes.find((node) => node.route === edge.target);
  assert.equal(source.locale, target.locale);
}

assert.equal(graph.hreflang_pairs.length, 109);
assert.equal(new Set(graph.hreflang_pairs.map((pair) => pair.translation_group)).size, 109);
for (const pair of graph.hreflang_pairs) {
  assert(routes.has(pair.en));
  assert(routes.has(pair['zh-CN']));
  assert.equal(pair.x_default, pair.en);
  assert.equal(pair.reciprocal, true);
  assert(!pair.translation_group.startsWith('legacy:'));
}

const expectedRedirects = {
  '/zh/personality/big-five/emotional-stability': '/zh/personality/big-five/neuroticism-low',
  '/zh/personality/big-five/high-agreeableness': '/zh/personality/big-five/agreeableness-high',
  '/zh/personality/big-five/high-conscientiousness': '/zh/personality/big-five/conscientiousness-high',
  '/zh/personality/big-five/high-extraversion': '/zh/personality/big-five/extraversion-high',
  '/zh/personality/big-five/high-neuroticism': '/zh/personality/big-five/neuroticism-high',
  '/zh/personality/big-five/high-openness': '/zh/personality/big-five/openness-high',
  '/zh/personality/big-five/low-agreeableness': '/zh/personality/big-five/agreeableness-low',
  '/zh/personality/big-five/low-conscientiousness': '/zh/personality/big-five/conscientiousness-low',
  '/zh/personality/big-five/low-extraversion': '/zh/personality/big-five/extraversion-low',
  '/zh/personality/big-five/low-openness': '/zh/personality/big-five/openness-low',
};
assert.equal(graph.redirects.length, 10);
assert.deepEqual(Object.fromEntries(graph.redirects.map((redirect) => [redirect.source, redirect.target])), expectedRedirects);
assert(graph.redirects.every((redirect) => redirect.status_code === 301 && redirect.exact_match && redirect.hop_count === 1 && !routes.has(redirect.source) && routes.has(redirect.target)));

assert.equal(overlap.controls.length, 10);
assert(overlap.controls.every((row) => routes.has(row.en_legacy_route) && routes.has(row.en_v2_route) && row.cannibalization_control === 'PASS_DISTINCT_INTENT'));
assert.deepEqual(targets.counts, {
  candidate_nodes: 231,
  inventory_routes: 231,
  internal_edges: 1199,
  validated_real_targets: 1199,
  dead_edges: 0,
  orphan_nodes: 0,
  sink_nodes: 0,
  self_links: 0,
  cross_locale_edges: 0,
  hreflang_pairs: 109,
  redirects: 10,
  redirect_chains_or_cycles: 0,
});
assert.deepEqual(targets.dead_edges, []);
assert.deepEqual(targets.orphan_routes, []);
assert.deepEqual(targets.sink_routes, []);
assert.equal(qa.status, 'PASS_NO_RELEASE_MUTATION');
assert.deepEqual(qa.counts, targets.counts);
assert(Object.values(qa.checks).every((value) => value === true));
const serialized = JSON.stringify(graph).toLowerCase();
for (const forbidden of ['/attempt/', '/report/', '/orders/', 'sitemap_release', 'llms_release', 'schema_release']) assert(!serialized.includes(forbidden));

console.log('Big Five PR35 validation passed: 231 real nodes / 1199 edges / 109 reciprocal hreflang pairs / 10 exact zh 301 redirects');
