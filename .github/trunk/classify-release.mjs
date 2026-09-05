import { execFileSync, spawnSync } from 'node:child_process';
import { classifyPaths } from './classify-paths.mjs';

const productionJob = 'Production exact-SHA activation, smoke, and LKG fallback';
const activationStep = 'Deploy once and automatically restore LKG after committed smoke failure';
const validSha = (value) => /^[a-f0-9]{40}$/.test(value ?? '') && value !== '0'.repeat(40);

export async function productionBaseline({ listRuns, listJobs }) {
  for (let page = 1; ; page++) {
    const runs = await listRuns(page);
    if (!Array.isArray(runs)) throw new Error('Invalid deployment run response');
    for (const run of runs) {
      if (run.status !== 'completed' || run.conclusion !== 'success'
        || run.head_branch !== 'main' || run.event !== 'workflow_run' || run.run_attempt !== 1) continue;
      if (!Number.isSafeInteger(run.id) || !validSha(run.head_sha)) throw new Error('Invalid deployment identity');
      const jobs = await listJobs(run.id);
      if (!Array.isArray(jobs)) throw new Error('Invalid deployment jobs response');
      const production = jobs.filter((job) => job.name === productionJob);
      if (production.length !== 1) throw new Error('Ambiguous production job evidence');
      const job = production[0];
      // Successful docs-only workflows have a skipped production job.
      if (job.conclusion === 'skipped') continue;
      if (job.status !== 'completed' || job.conclusion !== 'success'
        || job.steps?.filter((step) => step.name === activationStep && step.conclusion === 'success').length !== 1) {
        throw new Error('Successful workflow lacks production activation evidence');
      }
      return { sha: run.head_sha, runId: run.id };
    }
    if (runs.length < 100) throw new Error('No successful production activation found');
  }
}

export function classifyRelease({ pushBase, head, baseline, diffPaths, isAncestor }) {
  if (![pushBase, head, baseline.sha].every(validSha)
    || !isAncestor(pushBase, head) || !isAncestor(baseline.sha, head)) {
    throw new Error('Indeterminate or non-forward release baseline');
  }
  const pushPaths = diffPaths(pushBase, head);
  const push = classifyPaths(pushPaths);
  const pendingPaths = diffPaths(baseline.sha, head);
  const pendingRuntime = pendingPaths.length > 0 && classifyPaths(pendingPaths).deploy;
  const classification = pendingRuntime ? classifyPaths([...pushPaths, ...pendingPaths]) : push;
  return {
    ...classification,
    scope: {
      push_base_sha: pushBase,
      production_base_sha: baseline.sha,
      production_deploy_run_id: baseline.runId,
      validation_base_sha: pendingRuntime && isAncestor(baseline.sha, pushBase) ? baseline.sha : pushBase,
    },
  };
}

async function cli() {
  const repository = process.env.GITHUB_REPOSITORY;
  if (!/^[\w.-]+\/[\w.-]+$/.test(repository ?? '')) throw new Error('Invalid repository');
  const api = (path, query) => {
    try {
      return JSON.parse(execFileSync('gh', ['api', `repos/${repository}/${path}`, '--jq', query], {
        encoding: 'utf8', timeout: 30000, stdio: ['ignore', 'pipe', 'pipe'],
      }));
    } catch {
      throw new Error('Unable to read deployment evidence from GitHub');
    }
  };
  const baseline = await productionBaseline({
    listRuns: (page) => api(`actions/workflows/deploy.yml/runs?status=success&per_page=100&page=${page}`, '.workflow_runs | map({id, head_sha, status, conclusion, head_branch, event, run_attempt})'),
    listJobs: (runId) => {
      const result = api(`actions/runs/${runId}/attempts/1/jobs?per_page=100`, '{total_count, jobs: [.jobs[] | {name, status, conclusion, steps: [.steps[]? | {name, conclusion}]}]}');
      if (result.total_count !== result.jobs?.length) throw new Error('Incomplete deployment jobs response');
      return result.jobs;
    },
  });
  const classification = classifyRelease({
    pushBase: process.env.PUSH_BEFORE, head: process.env.GITHUB_SHA, baseline,
    isAncestor: (base, head) => spawnSync('git', ['merge-base', '--is-ancestor', base, head]).status === 0,
    diffPaths: (base, head) => execFileSync('git', ['diff', '--no-renames', '--name-only', '-z', base, head], {
      encoding: 'utf8',
    }).split('\0').filter(Boolean),
  });
  process.stdout.write(`${JSON.stringify(classification, null, 2)}\n`);
}

if (import.meta.url === `file://${process.argv[1]}`) {
  cli().catch((error) => { process.stderr.write(`${error.message}\n`); process.exitCode = 1; });
}
