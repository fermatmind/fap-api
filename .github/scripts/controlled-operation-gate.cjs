const crypto = require('node:crypto');

const KEY_PATTERN = /^[0-9a-f]{64}$/;
const DIGEST_PATTERN = /^sha256:[0-9a-f]{64}$/;
const ACTIVE_STATUSES = new Set(['queued', 'in_progress', 'waiting', 'requested', 'pending']);

function sha256(value) {
  return crypto.createHash('sha256').update(value, 'utf8').digest('hex');
}

function operationKey({ repository, workflow, scope, identity }) {
  for (const [name, value] of Object.entries({ repository, workflow, scope, identity })) {
    if (typeof value !== 'string' || value.length === 0 || /[\r\n]/.test(value)) {
      throw new Error(`${name} must be a non-empty single-line string`);
    }
  }
  const identitySha = sha256(identity);
  return sha256([
    'fermatmind.operation.v1',
    `repository=${repository}`,
    `workflow=${workflow}`,
    `scope=${scope}`,
    `identity_sha256=${identitySha}`,
    '',
  ].join('\n'));
}

function renderReceiptName(template, run) {
  return template
    .replaceAll('{operation_key}', run.operationKey)
    .replaceAll('{run_id}', String(run.id))
    .replaceAll('{run_attempt}', String(run.run_attempt));
}

function classify({ candidates, artifactsByRun, receiptTemplate, operationKey: key }) {
  const ordered = [...candidates].sort((left, right) => left.id - right.id);
  if (ordered.length === 0) return { decision: 'execute' };

  const owner = ordered[0];
  if (ACTIVE_STATUSES.has(owner.status)) {
    return { decision: 'attach_active', owner };
  }
  if (owner.status !== 'completed' || owner.conclusion !== 'success') {
    return { decision: 'blocked_prior_terminal', owner };
  }

  const successfulOwners = [];
  for (const run of ordered.filter((candidate) => candidate.status === 'completed' && candidate.conclusion === 'success')) {
    const expectedName = renderReceiptName(receiptTemplate, { ...run, operationKey: key });
    const matches = (artifactsByRun.get(run.id) || []).filter(
      (artifact) => artifact.name === expectedName && artifact.expired === false,
    );
    if (matches.length === 1 && DIGEST_PATTERN.test(String(matches[0].digest || ''))) {
      successfulOwners.push({ run, artifact: matches[0] });
    } else if (run.id === owner.id) {
      return { decision: 'blocked_receipt_invalid', owner, receiptCount: matches.length };
    }
  }
  if (successfulOwners.length !== 1 || successfulOwners[0].run.id !== owner.id) {
    return { decision: 'blocked_ambiguous_owners', owner, ownerCount: successfulOwners.length };
  }
  return { decision: 'attach_success', owner, artifact: successfulOwners[0].artifact };
}

async function gate({ github, context, core, claimedKey, expectedKey, workflowFile, receiptTemplate, matchHeadSha, eventName = 'workflow_dispatch', candidateMarker = '' }) {
  if (!KEY_PATTERN.test(claimedKey) || claimedKey !== expectedKey) {
    core.setFailed('Claimed operation key does not match the recomputed immutable operation identity.');
    return;
  }
  const currentRunId = Number(context.runId);
  const runs = await github.paginate(github.rest.actions.listWorkflowRuns, {
    owner: context.repo.owner,
    repo: context.repo.repo,
    workflow_id: workflowFile,
    per_page: 100,
  });
  const marker = candidateMarker || `[op:${claimedKey}]`;
  const candidates = runs.filter((run) => {
    if (run.id >= currentRunId || run.path !== `.github/workflows/${workflowFile}`) return false;
    if (matchHeadSha) {
      return run.event === 'push' && run.head_sha === context.sha;
    }
    return run.event === eventName && String(run.display_title || '').includes(marker);
  });

  const artifactsByRun = new Map();
  for (const run of candidates.filter((candidate) => candidate.status === 'completed' && candidate.conclusion === 'success')) {
    const response = await github.rest.actions.listWorkflowRunArtifacts({
      owner: context.repo.owner,
      repo: context.repo.repo,
      run_id: run.id,
      per_page: 100,
    });
    artifactsByRun.set(run.id, response.data.artifacts);
  }

  const result = classify({ candidates, artifactsByRun, receiptTemplate, operationKey: claimedKey });
  core.setOutput('decision', result.decision);
  core.setOutput('operation_key', claimedKey);
  if (result.owner) {
    core.setOutput('owner_run_id', String(result.owner.id));
    core.setOutput('owner_run_attempt', String(result.owner.run_attempt));
  }
  if (result.artifact) {
    core.setOutput('owner_receipt_artifact_id', String(result.artifact.id));
    core.setOutput('owner_receipt_artifact_digest', result.artifact.digest);
  }
  if (result.decision.startsWith('blocked_')) {
    core.setFailed(`Controlled operation gate blocked: ${result.decision}.`);
  } else if (result.decision !== 'execute') {
    core.notice(`Controlled operation attached to owner run ${result.owner.id}: ${result.decision}.`);
  }
}

if (require.main === module) {
  const [, , command, ...args] = process.argv;
  if (command !== 'key' || args.length !== 8) {
    process.stderr.write('usage: controlled-operation-gate.cjs key <repository> <workflow> <scope> <identity>\n');
    process.exit(64);
  }
  const values = Object.fromEntries(Array.from({ length: 4 }, (_, index) => [args[index * 2], args[index * 2 + 1]]));
  process.stdout.write(`${operationKey({
    repository: values['--repository'],
    workflow: values['--workflow'],
    scope: values['--scope'],
    identity: values['--identity'],
  })}\n`);
}

module.exports = { classify, gate, operationKey, renderReceiptName };
