import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const sha = value => /^[a-f0-9]{40}$/.test(value ?? '');

// Artifact reads are idempotent. A transient GitHub download failure must not
// require replaying CI or any deployment; binding/digest failures still stop.
export function downloadProductionArtifact(repository, artifact, {
  request = () => execFileSync('gh', ['api', `repos/${repository}/actions/artifacts/${artifact.id}/zip`], {
    timeout: 30000, stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 32 * 1024 * 1024,
  }),
  wait = milliseconds => Atomics.wait(new Int32Array(new SharedArrayBuffer(4)), 0, 0, milliseconds),
} = {}) {
  if (artifact.expired || !/^sha256:[a-f0-9]{64}$/.test(artifact.digest ?? '')) throw new Error('Invalid production artifact digest');
  let bytes;
  for (let attempt = 0; attempt < 3; attempt++) {
    try { bytes = request(); break; }
    catch (error) {
      if (attempt === 2 || !/\bHTTP (?:429|500|502|503|504)\b/.test(String(error.stderr ?? ''))) throw error;
      wait((attempt + 1) * 1000);
    }
  }
  if (`sha256:${createHash('sha256').update(bytes).digest('hex')}` !== artifact.digest) throw new Error('Production artifact digest mismatch');
  return bytes;
}

export function verifyProductionBinding({ run, artifact, timing, outcome, ci, receipt, job }) {
  const candidate = artifact.name.replace(/^trunk-production-/, '');
  if (!sha(candidate) || timing.schema_version !== 'fermatmind.trunk-delivery-timing.v1'
    || timing.sha !== candidate || (timing.deploy_run_id != null && String(timing.deploy_run_id) !== String(run.id))
    || (timing.deploy_run_attempt != null && Number(timing.deploy_run_attempt) !== run.run_attempt)
    || !Number.isFinite(Date.parse(timing.production_smoke_completed_at))
    || ci.head_sha !== candidate || ci.head_branch !== 'main' || ci.event !== 'push'
    || ci.status !== 'completed' || ci.conclusion !== 'success' || ci.run_attempt !== 1
    || receipt.schema_version !== 'fermatmind.trunk-validation.v1' || receipt.sha !== candidate
    || String(receipt.ci_run_id) !== String(ci.id) || receipt.result !== 'success' || receipt.classification?.deploy !== true
    || (timing.ci_run_id != null && String(timing.ci_run_id) !== String(ci.id))
    || (job?.conclusion !== undefined && job.conclusion !== 'success' && timing.production_outcome !== 'success')
    || (timing.repository != null && timing.repository !== ci.repository?.full_name)
    || (timing.production_outcome != null && timing.production_outcome !== 'success')
    || (outcome && (outcome.revision !== candidate || outcome.status !== 'success' || outcome.phase !== 'complete'
      || outcome.exit_code !== 0 || outcome.rollback !== 'not_needed'))) throw new Error('Invalid production candidate binding');
  return candidate;
}

export function readProductionCI(candidate, timing, api) {
  const valid = ci => ci?.head_sha === candidate && ci.head_branch === 'main' && ci.event === 'push'
    && ci.run_attempt === 1 && ci.status === 'completed' && ci.conclusion === 'success';
  if (timing.ci_run_id != null) {
    const id = String(timing.ci_run_id);
    if (!/^[1-9][0-9]*$/.test(id)) throw new Error('Invalid production CI binding');
    // The immutable timing receipt already identifies the exact CI run. A
    // filtered run-list response is not authority for whether that run exists.
    const ci = api(`actions/runs/${id}`);
    if (!valid(ci) || ci.path !== '.github/workflows/ci.yml' || String(ci.id) !== id) throw new Error('Invalid production CI binding');
    return ci;
  }
  // Retain the timestamp-bound compatibility path for older timing receipts.
  const cis = api(`actions/workflows/ci.yml/runs?head_sha=${candidate}&event=push&per_page=100`).workflow_runs
    .filter(ci => valid(ci) && ci.created_at === timing.pushed_at);
  if (cis.length !== 1) throw new Error('Ambiguous production CI binding');
  return cis[0];
}

const productionJobName = 'Production exact-SHA activation, smoke, and LKG fallback';
const activationStepName = 'Deploy once and automatically restore LKG after committed smoke failure';

// GitHub can return a terminal job with an empty steps array. Read the same
// authenticated job log; never substitute a timing receipt for activation.
export function recoverProductionSteps(job, log) {
  if (job.name !== productionJobName || job.status !== 'completed' || job.conclusion !== 'success'
    || !Array.isArray(job.steps) || job.steps.length !== 0) throw new Error('Invalid missing-step recovery');
  const lines = log.replace(/\x1b\[[0-9;]*m/g, '').split('\n')
    .map(line => line.replace(/^\d{4}-\d{2}-\d{2}T\S+Z /, '').trimEnd());
  const position = text => {
    const matches = lines.flatMap((line, index) => line === text ? [index] : []);
    if (matches.length !== 1) throw new Error('Missing or ambiguous production activation log');
    return matches[0];
  };
  const ordered = [
    `Complete job name: ${productionJobName}`,
    '##[group]task deploy:symlink',
    '##[group]task queue:reload-workers',
    '##[group]task healthcheck:public',
    '##[group]task healthcheck:auth-guest-contract',
    '##[group]task healthcheck:scale-lookup',
    '[production] info successfully deployed!',
    '##[group]task cache:accept-public-projection',
  ].map(position);
  if (ordered.some((index, i) => i > 0 && index <= ordered[i - 1])
    || lines.some(line => line.startsWith('##[error]'))) throw new Error('Invalid production activation log');
  return {...job, steps: [{name: activationStepName, conclusion: 'success'}]};
}

export function githubProductionEvidence(repository) {
  const api = path => JSON.parse(execFileSync('gh', ['api', `repos/${repository}/${path}`], {
    encoding: 'utf8', timeout: 30000, stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 8 * 1024 * 1024,
  }));
  const artifacts = runId => {
    const result = api(`actions/runs/${runId}/artifacts?per_page=100`);
    if (result.total_count !== result.artifacts?.length) throw new Error('Incomplete artifact inventory');
    return result.artifacts;
  };
  const readArchive = artifact => {
    const bytes = downloadProductionArtifact(repository, artifact);
    const temp = mkdtempSync(join(tmpdir(), 'production-evidence-'));
    try {
      const zip = join(temp, 'artifact.zip'); writeFileSync(zip, bytes);
      const members = execFileSync('unzip', ['-Z1', zip], {encoding:'utf8'}).trim().split('\n');
      const documents = new Map();
      for (const name of ['trunk-delivery-timing.json', 'deploy-outcome.json', 'trunk-validation.json','trunk-deploy-skip.json']) {
        const selected = members.filter(member => member === name || member.endsWith(`/${name}`));
        if (selected.length > 1) throw new Error(`Ambiguous production evidence: ${name}`);
        if (!selected.length) continue;
        documents.set(name, JSON.parse(execFileSync('unzip', ['-p', zip, selected[0]], {encoding:'utf8', maxBuffer:8*1024*1024})));
      }
      return name => { if (!documents.has(name)) throw new Error(`Missing production evidence: ${name}`); return documents.get(name); };
    } finally { rmSync(temp, {recursive:true, force:true}); }
  };
  const reader = async (run, job) => {
    const matches = artifacts(run.id).filter(a => /^trunk-production-[a-f0-9]{40}$/.test(a.name));
    if (matches.length !== 1) throw new Error('Ambiguous production candidate artifact');
    const artifact = matches[0];
    // Archives are read without extraction; no artifact path is executed.
    const read = readArchive(artifact);
    const timing = read('trunk-delivery-timing.json');
    const candidate = artifact.name.slice('trunk-production-'.length);
    const ci = readProductionCI(candidate, timing, api);
    const validations = artifacts(ci.id).filter(a => a.name === `trunk-validation-${candidate}`);
    if (validations.length !== 1) throw new Error('Ambiguous CI validation artifact');
    const receipt = readArchive(validations[0])('trunk-validation.json');
    const outcome = repository.endsWith('/fap-web') ? read('deploy-outcome.json') : null;
    return verifyProductionBinding({run, artifact, timing, outcome, ci, receipt, job});
  };
  reader.jobs = async (runId) => {
    const result = api(`actions/runs/${runId}/attempts/1/jobs?per_page=100`);
    if (result.total_count !== result.jobs?.length) throw new Error('Incomplete deployment jobs response');
    for (let i = 0; i < result.jobs.length; i++) {
      const job = result.jobs[i];
      if (job.name !== productionJobName || job.status !== 'completed' || job.conclusion !== 'success'
        || !Array.isArray(job.steps) || job.steps.length !== 0) continue;
      if (!Number.isSafeInteger(job.id)) throw new Error('Invalid production job identity');
      // A bound native skip proves nonactivation. It is still fully verified by
      // reader.skip before the resolver can move to an earlier accepted run.
      if (artifacts(runId).some(a => /^trunk-deploy-skip-[a-f0-9]{40}$/.test(a.name))) {
        result.jobs[i] = {...job, steps: [{name: activationStepName, conclusion: 'skipped'}]};
      } else {
        const log = execFileSync('gh', ['api', `repos/${repository}/actions/jobs/${job.id}/logs`], {
          encoding: 'utf8', timeout: 30000, stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 32 * 1024 * 1024,
        });
        result.jobs[i] = recoverProductionSteps(job, log);
      }
    }
    return result.jobs;
  };
  reader.skip = async (run,jobs) => {
    const inventory=artifacts(run.id),matches=inventory.filter(a=>/^trunk-deploy-skip-[a-f0-9]{40}$/.test(a.name));
    if(!matches.length) {
      // Explicit legacy mapping: a successful documentation job and validated
      // policy job together prove no application activation. No candidate is
      // promoted to a baseline by this compatibility path.
      const docs=jobs.filter(j=>['Docs-only deployment skip receipt','docs-only'].includes(j.name)&&j.conclusion==='success');
      const policy=jobs.filter(j=>/^Bind successful main CI/.test(j.name)&&j.conclusion==='success');
      if(docs.length===1&&policy.length===1)return;
      if(run.conclusion==='failure'||run.status==='in_progress')return; // no successful activation claim
      throw new Error('Missing bound nonactivation evidence');
    }
    if(matches.length!==1)throw new Error('Ambiguous deployment skip evidence');
    const proof=readArchive(matches[0])('trunk-deploy-skip.json');
    if(proof.schema_version!=='fermatmind.trunk-deploy-skip.v1'||proof.repository!==repository
      ||proof.sha!==matches[0].name.slice('trunk-deploy-skip-'.length)||!sha(proof.sha)
      ||String(proof.deploy_run_id)!==String(run.id)||proof.deploy_run_attempt!==1||proof.mutation_started!==false)throw new Error('Invalid deployment skip binding');
    const ci=api(`actions/runs/${proof.ci_run_id}`);
    const validation=artifacts(ci.id).filter(a=>a.name===`trunk-validation-${proof.sha}`);
    if(validation.length!==1||ci.head_sha!==proof.sha||ci.head_branch!=='main'||ci.event!=='push'||ci.conclusion!=='success'||ci.run_attempt!==1)throw new Error('Invalid skip CI binding');
    const receipt=readArchive(validation[0])('trunk-validation.json');
    if(receipt.sha!==proof.sha||receipt.result!=='success'||String(receipt.ci_run_id)!==String(ci.id))throw new Error('Invalid skip validation receipt');
    if(proof.reason==='docs_rules_tests_only'&&receipt.classification?.deploy===false)return;
    if(!['already_accepted','stale_accepted_ancestor'].includes(proof.reason)||!sha(proof.accepted_sha)||proof.accepted_run_id===run.id)throw new Error('Unknown skip disposition');
    const acceptedRun=api(`actions/runs/${proof.accepted_run_id}`);
    const acceptedJobs=await reader.jobs(proof.accepted_run_id);
    const activations=acceptedJobs.filter(job=>job.name.startsWith('Production exact-SHA') && job.steps?.some(step=>step.name.startsWith('Deploy once')&&step.conclusion==='success'));
    if(activations.length!==1) throw new Error('Missing accepted activation binding');
    const accepted=await reader(acceptedRun,activations[0]);
    if(accepted!==proof.accepted_sha||(proof.reason==='already_accepted'?proof.sha!==accepted:!sha(proof.sha)))throw new Error('Invalid accepted skip baseline');
    if(proof.reason==='stale_accepted_ancestor') execFileSync('git',['merge-base','--is-ancestor',proof.sha,accepted],{stdio:'ignore'});
  };
  return reader;
}
