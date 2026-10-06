import { execFileSync } from 'node:child_process';
import { createHash } from 'node:crypto';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
const sha = value => /^[a-f0-9]{40}$/.test(value ?? '');

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
    if (artifact.expired || !/^sha256:[a-f0-9]{64}$/.test(artifact.digest ?? '')) throw new Error('Invalid production artifact digest');
    const bytes = execFileSync('gh', ['api', `repos/${repository}/actions/artifacts/${artifact.id}/zip`], {
      timeout: 30000, stdio: ['ignore', 'pipe', 'pipe'], maxBuffer: 32 * 1024 * 1024,
    });
    if (`sha256:${createHash('sha256').update(bytes).digest('hex')}` !== artifact.digest) throw new Error('Production artifact digest mismatch');
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
    const cis = api(`actions/workflows/ci.yml/runs?head_sha=${candidate}&event=push&per_page=100`).workflow_runs
      .filter(ci => ci.head_branch === 'main' && ci.run_attempt === 1 && ci.status === 'completed' && ci.conclusion === 'success'
        && (timing.ci_run_id != null ? String(ci.id) === String(timing.ci_run_id) : ci.created_at === timing.pushed_at));
    if (cis.length !== 1) throw new Error('Ambiguous production CI binding');
    const ci = cis[0];
    const validations = artifacts(ci.id).filter(a => a.name === `trunk-validation-${candidate}`);
    if (validations.length !== 1) throw new Error('Ambiguous CI validation artifact');
    const receipt = readArchive(validations[0])('trunk-validation.json');
    const outcome = repository.endsWith('/fap-web') ? read('deploy-outcome.json') : null;
    return verifyProductionBinding({run, artifact, timing, outcome, ci, receipt, job});
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
    const acceptedJobs=api(`actions/runs/${proof.accepted_run_id}/attempts/1/jobs?per_page=100`);
    if(acceptedJobs.total_count!==acceptedJobs.jobs?.length) throw new Error('Incomplete accepted job inventory');
    const activations=acceptedJobs.jobs.filter(job=>job.name.startsWith('Production exact-SHA') && job.steps?.some(step=>step.name.startsWith('Deploy once')&&step.conclusion==='success'));
    if(activations.length!==1) throw new Error('Missing accepted activation binding');
    const accepted=await reader(acceptedRun,activations[0]);
    if(accepted!==proof.accepted_sha||(proof.reason==='already_accepted'?proof.sha!==accepted:!sha(proof.sha)))throw new Error('Invalid accepted skip baseline');
    if(proof.reason==='stale_accepted_ancestor') execFileSync('git',['merge-base','--is-ancestor',proof.sha,accepted],{stdio:'ignore'});
  };
  return reader;
}
