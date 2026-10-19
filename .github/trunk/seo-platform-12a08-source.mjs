import {execFileSync} from 'node:child_process';
import {appendFileSync, readFileSync, writeFileSync} from 'node:fs';
import {githubProductionEvidence} from './production-evidence.mjs';

export function eligibleDeploy(run, jobs, repository) {
 if (run.repository?.full_name !== repository || run.path !== '.github/workflows/deploy.yml'
   || run.head_branch !== 'main' || run.event !== 'workflow_run' || run.run_attempt !== 1
   || run.status !== 'completed' || !['success','failure'].includes(run.conclusion)) return false;
 const production=jobs.filter(j=>j.name === 'Production exact-SHA activation, smoke, and LKG fallback');
 return production.length === 1 && production[0].status === 'completed' && production[0].conclusion === 'success'
   && production[0].steps?.some(s=>s.name.startsWith('Deploy once') && s.conclusion === 'success')
   && jobs.some(j=>j.name === 'Staging exact-SHA deploy and smoke' && j.status === 'completed' && j.conclusion === 'success')
   && jobs.every(j=>!['failure','cancelled','timed_out','action_required'].includes(j.conclusion)
     || j.name === 'Publish A08 software evidence with operations independent');
}

// Producer code and the current software under examination are different
// identities during rollback. Neither may borrow the other's CI result.
export function verifyProducer({repository, sha, checkedOutSha, api}) {
 if(repository !== 'fermatmind/fap-api' || !/^[a-f0-9]{40}$/.test(sha??'') || checkedOutSha !== sha) throw new Error('A08_PRODUCER_IDENTITY_HOLD');
 const inventory=api(`actions/workflows/ci.yml/runs?head_sha=${sha}&event=push&per_page=100`);
 const matches=inventory.workflow_runs.filter(run=>run.head_sha === sha && run.repository?.full_name === repository
   && run.path === '.github/workflows/ci.yml' && run.head_branch === 'main' && run.event === 'push'
   && run.run_attempt === 1 && run.status === 'completed' && run.conclusion === 'success');
 if(matches.length !== 1) throw new Error('A08_PRODUCER_CI_HOLD');
 return {producer_sha:sha,ci_run_id:matches[0].id};
}

export async function selectSource({repository, state, eventRunId, api, readProduction}) {
 if(repository !== 'fermatmind/fap-api' || !/^[a-f0-9]{40}$/.test(state.sha??'')) throw new Error('A08_SOURCE_IDENTITY_HOLD');
 if(!eventRunId && state.activation_state === 'READY'
   && ['sha','generation','version_vector_hash','lock_identity','release_id'].every(key=>Object.hasOwn(state.activation_fence??{},key) && state.activation_fence[key] === state[key])) return {disposition:'VERIFIED_UNCHANGED'};
 const inspect=async run=>{
  const inventory=api(`actions/runs/${run.id}/attempts/1/jobs?per_page=100`);
  if(inventory.total_count !== inventory.jobs?.length) throw new Error('A08_JOB_INVENTORY_HOLD');
  if(!eligibleDeploy(run,inventory.jobs,repository)) return null;
  const {sha,ci_run_id}=await readProduction(run, inventory.jobs.find(j=>j.name.startsWith('Production exact-SHA')));
  if(sha !== state.sha) return null;
  const artifacts=api(`actions/runs/${run.id}/artifacts?per_page=100`);
  if(artifacts.total_count !== artifacts.artifacts?.length) throw new Error('A08_ARTIFACT_INVENTORY_HOLD');
  const matching=artifacts.artifacts.filter(a=>a.name === `trunk-production-${sha}` && !a.expired);
  if(matching.length !== 1) throw new Error('A08_PRODUCTION_ARTIFACT_HOLD');
  return {disposition:'BUILD',sha,deploy_run_id:run.id,ci_run_id};
 };
 if(eventRunId) {
  const run=api(`actions/runs/${eventRunId}`);
  return await inspect(run) ?? {disposition:'SUPERSEDED_OR_INELIGIBLE'};
 }
 for(let page=1;;page++) {
  const inventory=api(`actions/workflows/deploy.yml/runs?status=completed&per_page=100&page=${page}`);
  for(const run of inventory.workflow_runs) {
   const artifacts=api(`actions/runs/${run.id}/artifacts?per_page=100`);
   if(!artifacts.artifacts?.some(a=>a.name === `trunk-production-${state.sha}` && !a.expired)) continue;
   const selected=await inspect(run); if(selected) return selected;
  }
  if(inventory.workflow_runs.length < 100) throw new Error('A08_CURRENT_SOURCE_HOLD');
 }
}

if(import.meta.url === `file://${process.argv[1]}`) {
 const repository=process.env.GITHUB_REPOSITORY;
 const api=path=>JSON.parse(execFileSync('gh',['api',`repos/${repository}/${path}`],{encoding:'utf8',timeout:30000,maxBuffer:16*1024*1024}));
 if(process.argv[2] === '--verify-producer') {
  const proof=verifyProducer({repository,sha:process.env.GITHUB_SHA,
   checkedOutSha:execFileSync('git',['rev-parse','HEAD'],{encoding:'utf8'}).trim(),api});
  writeFileSync('a08-producer.json',JSON.stringify(proof)+'\n');
  appendFileSync(process.env.GITHUB_STEP_SUMMARY,`A08 producer: ${proof.producer_sha}; successful CI: ${proof.ci_run_id}\n`);
  process.exit(0);
 }
 const productionReader=githubProductionEvidence(repository);
 const readProduction=async(run,job)=>{
  const sha=await productionReader(run,job);
  const artifact=api(`actions/runs/${run.id}/artifacts?per_page=100`).artifacts.find(a=>a.name === `trunk-production-${sha}`);
  // Production reader has validated this immutable archive and its actual SHA.
  const {downloadProductionArtifact}=await import('./production-evidence.mjs');
  writeFileSync('production-source.zip',downloadProductionArtifact(repository,artifact));
  const timing=JSON.parse(execFileSync('unzip',['-p','production-source.zip','trunk-delivery-timing.json'],{encoding:'utf8'}));
  return {sha,ci_run_id:timing.ci_run_id};
 };
 const selected=await selectSource({repository,state:JSON.parse(readFileSync('a08-current.json')),eventRunId:process.env.SOURCE_DEPLOY_RUN_ID,api,readProduction});
 writeFileSync('a08-source.json',JSON.stringify(selected)+'\n');
 appendFileSync(process.env.GITHUB_OUTPUT,`disposition=${selected.disposition}\n`);
 if(selected.disposition === 'BUILD') {
  if(!/^\d+$/.test(String(selected.ci_run_id))) throw new Error('A08_CI_SOURCE_HOLD');
  for(const [key,value] of Object.entries({DEPLOY_SHA:selected.sha,SOURCE_DEPLOY_RUN_ID:selected.deploy_run_id,CI_RUN_ID:selected.ci_run_id})) appendFileSync(process.env.GITHUB_ENV,`${key}=${value}\n`);
 }
 appendFileSync(process.env.GITHUB_STEP_SUMMARY,`A08 independent evidence: ${selected.disposition}\n`);
}
