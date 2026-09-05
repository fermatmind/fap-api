import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';
import { productionBaseline, classifyRelease } from './classify-release.mjs';

const prod = 'a'.repeat(40), before = 'b'.repeat(40), head = 'c'.repeat(40);
const run = (id, sha = prod) => ({ id, head_sha: sha, status: 'completed', conclusion: 'success', head_branch: 'main', event: 'workflow_run', run_attempt: 1 });
const job = (conclusion = 'success') => ({ name: 'Production exact-SHA activation, smoke, and LKG fallback', status: 'completed', conclusion, steps: [{ name: 'Deploy once and automatically restore LKG after committed smoke failure', conclusion }] });
const scope = (pushPaths, pendingPaths) => classifyRelease({
  pushBase: before, head, baseline: { sha: prod, runId: 7 },
  isAncestor: () => true, diffPaths: (base) => base === before ? pushPaths : pendingPaths,
});

test('tests-only correction carries the unreleased runtime and its validation scope', () => {
  const result = scope(['backend/tests/Feature/Content/ContentPackLintTest.php'], [
    'backend/app/Services/Content/ContentCompileService.php', 'backend/tests/Feature/Content/ContentPackLintTest.php',
  ]);
  assert.equal(result.deploy, true);
  assert.equal(result.flags.content_assets, true);
  assert.equal(result.tests_changed, true);
  assert.equal(result.operations.publisher_required, false);
  assert.equal(result.scope.validation_base_sha, prod);
  assert.equal(result.scope.push_base_sha, before);
  assert.equal(result.scope.production_deploy_run_id, 7);
});

test('docs-only changes after an accepted runtime release still skip deployment', () => {
  const result = scope(['docs/new.md'], ['docs/new.md', 'backend/tests/Feature/OldTest.php']);
  assert.equal(result.deploy, false);
  assert.equal(result.tests_changed, false);
  assert.deepEqual(result.paths, ['docs/new.md']);
  assert.equal(result.scope.validation_base_sha, before);
});

test('pending migrations and payments keep the union of required checks', () => {
  const result = scope(['backend/tests/Feature/FixTest.php'], [
    'backend/database/migrations/2026_09_06_000000_expand_orders.php', 'backend/app/Services/Payments/PaymentService.php',
  ]);
  assert.equal(result.flags.backward_compatible_migration, true);
  assert.equal(result.flags.payment, true);
  assert.equal(result.tests_changed, true);
});

test('push paths remain checked even when they cancel an unreleased change', () => {
  const result = scope(['backend/app/Services/Example.php'], []);
  assert.equal(result.deploy, true);
  assert.equal(result.scope.validation_base_sha, before);
});

test('unknown, zero, and non-forward baselines fail closed', () => {
  for (const sha of ['unknown', '0'.repeat(40), prod]) {
    assert.throws(() => classifyRelease({ pushBase: before, head, baseline: { sha }, isAncestor: () => false }), /baseline/);
  }
});

test('skip receipts and failed or rerun workflows cannot become the production baseline', async () => {
  const baseline = await productionBaseline({
    listRuns: async () => [run(10, head), { ...run(9), conclusion: 'failure' }, { ...run(8), run_attempt: 2 }, run(7)],
    listJobs: async (id) => { assert.ok([10, 7].includes(id)); return [job(id === 10 ? 'skipped' : 'success')]; },
  });
  assert.deepEqual(baseline, { sha: prod, runId: 7 });
});

test('timing-only or incomplete activation evidence is rejected', async () => {
  for (const jobs of [[], [job(), job()], [{ ...job(), steps: [{ name: 'Persist push-to-production timing receipt', conclusion: 'success' }] }]]) {
    await assert.rejects(productionBaseline({ listRuns: async () => [run(7)], listJobs: async () => jobs }));
  }
});

test('production baseline search continues past a full page of skipped releases', async () => {
  const pages = [];
  const baseline = await productionBaseline({
    listRuns: async (page) => { pages.push(page); return page === 1 ? Array.from({ length: 100 }, (_, i) => run(1000 + i)) : [run(7)]; },
    listJobs: async (id) => [job(id === 7 ? 'success' : 'skipped')],
  });
  assert.deepEqual(pages, [1, 2]);
  assert.equal(baseline.runId, 7);
});

test('missing evidence and API failures cannot silently select deploy-skip', async () => {
  await assert.rejects(productionBaseline({ listRuns: async () => [], listJobs: async () => [] }), /No successful/);
  await assert.rejects(productionBaseline({ listRuns: async () => { throw new Error('unavailable'); } }), /unavailable/);
});

test('CI uses the release-aware scope for downstream test and migration selection', () => {
  const workflow = readFileSync(new URL('../workflows/ci.yml', import.meta.url), 'utf8');
  assert.match(workflow, /node \.github\/trunk\/classify-release\.mjs/);
  assert.match(workflow, /base_sha="\$\(jq -r \.scope\.validation_base_sha/);
  assert.match(workflow, /PUSH_BEFORE: \$\{\{ github\.event\.before \}\}/);
});
