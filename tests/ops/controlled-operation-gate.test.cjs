const assert = require('node:assert/strict');
const test = require('node:test');

const { classify, gate, operationKey } = require('../../.github/scripts/controlled-operation-gate.cjs');

const key = operationKey({
  repository: 'fermatmind/fap-api',
  workflow: '.github/workflows/example.yml',
  scope: 'example',
  identity: 'mode=apply|sha=abc',
});
const receiptTemplate = 'receipt-{operation_key}-{run_id}-{run_attempt}';
const run = (overrides = {}) => ({ id: 10, run_attempt: 1, status: 'completed', conclusion: 'success', ...overrides });
const artifact = (runId = 10) => ({ id: 99, name: `receipt-${key}-${runId}-1`, expired: false, digest: `sha256:${'a'.repeat(64)}` });

test('key is deterministic and identity-bound', () => {
  assert.match(key, /^[0-9a-f]{64}$/);
  assert.equal(key, operationKey({ repository: 'fermatmind/fap-api', workflow: '.github/workflows/example.yml', scope: 'example', identity: 'mode=apply|sha=abc' }));
  assert.notEqual(key, operationKey({ repository: 'fermatmind/fap-api', workflow: '.github/workflows/example.yml', scope: 'example', identity: 'mode=preflight|sha=abc' }));
});

test('new operation executes and an active owner attaches', () => {
  assert.equal(classify({ candidates: [], artifactsByRun: new Map(), receiptTemplate, operationKey: key }).decision, 'execute');
  assert.equal(classify({ candidates: [run({ status: 'in_progress', conclusion: null })], artifactsByRun: new Map(), receiptTemplate, operationKey: key }).decision, 'attach_active');
});

test('successful owner requires one immutable receipt', () => {
  const artifacts = new Map([[10, [artifact()]]]);
  assert.equal(classify({ candidates: [run()], artifactsByRun: artifacts, receiptTemplate, operationKey: key }).decision, 'attach_success');
  assert.equal(classify({ candidates: [run()], artifactsByRun: new Map([[10, []]]), receiptTemplate, operationKey: key }).decision, 'blocked_receipt_invalid');
  assert.equal(classify({ candidates: [run()], artifactsByRun: new Map([[10, [{ ...artifact(), digest: '' }]]]), receiptTemplate, operationKey: key }).decision, 'blocked_receipt_invalid');
});

test('failed owner and multiple successful owners fail closed', () => {
  assert.equal(classify({ candidates: [run({ conclusion: 'failure' })], artifactsByRun: new Map(), receiptTemplate, operationKey: key }).decision, 'blocked_prior_terminal');
  const second = run({ id: 11 });
  const artifacts = new Map([[10, [artifact(10)]], [11, [artifact(11)]]]);
  assert.equal(classify({ candidates: [run(), second], artifactsByRun: artifacts, receiptTemplate, operationKey: key }).decision, 'blocked_ambiguous_owners');
});

test('same workflow run rerun is not represented as a second candidate', () => {
  assert.equal(classify({ candidates: [], artifactsByRun: new Map(), receiptTemplate, operationKey: key }).decision, 'execute');
});

test('forged claimed key fails before any GitHub operation lookup', async () => {
  let paginated = false;
  const failures = [];
  await gate({
    github: { paginate: async () => { paginated = true; return []; }, rest: { actions: {} } },
    context: { runId: 100, repo: { owner: 'fermatmind', repo: 'fap-api' }, sha: 'a'.repeat(40) },
    core: { setFailed: (message) => failures.push(message) },
    claimedKey: 'f'.repeat(64),
    expectedKey: key,
    workflowFile: 'example.yml',
    receiptTemplate,
    matchHeadSha: false,
  });
  assert.equal(paginated, false);
  assert.equal(failures.length, 1);
});

test('same run id on a later attempt remains the owner instead of a duplicate', async () => {
  const outputs = new Map();
  await gate({
    github: {
      paginate: async () => [{ id: 100, path: '.github/workflows/example.yml', event: 'workflow_dispatch', display_title: `[op:${key}]`, status: 'completed', conclusion: 'failure' }],
      rest: { actions: { listWorkflowRuns() {} } },
    },
    context: { runId: 100, repo: { owner: 'fermatmind', repo: 'fap-api' }, sha: 'a'.repeat(40) },
    core: { setFailed: assert.fail, setOutput: (name, value) => outputs.set(name, value), notice() {} },
    claimedKey: key,
    expectedKey: key,
    workflowFile: 'example.yml',
    receiptTemplate,
    matchHeadSha: false,
  });
  assert.equal(outputs.get('decision'), 'execute');
});
