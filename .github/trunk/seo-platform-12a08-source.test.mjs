import assert from 'node:assert/strict';
import test from 'node:test';
import {readFileSync} from 'node:fs';
import {eligibleDeploy,selectSource,verifyProducer} from './seo-platform-12a08-source.mjs';
import {phpRepairBaseline} from './nightly-repair.mjs';

const repository='fermatmind/fap-api',sha='a'.repeat(40);
const run={id:7,repository:{full_name:repository},path:'.github/workflows/deploy.yml',head_branch:'main',event:'workflow_run',run_attempt:1,status:'completed',conclusion:'success'};
const jobs=[{name:'Production exact-SHA activation, smoke, and LKG fallback',status:'completed',conclusion:'success',steps:[{name:'Deploy once through existing boundary',conclusion:'success'}]},
 {name:'Staging exact-SHA deploy and smoke',status:'completed',conclusion:'success'}];

test('production completion and source identity cannot be replaced by activation alone',()=>{
 assert.equal(eligibleDeploy(run,jobs,repository),true);
 assert.equal(eligibleDeploy({...run,conclusion:'failure'},[...jobs,{name:'Publish A08 software evidence with operations independent',conclusion:'failure'}],repository),true);
 for(const changed of [{status:'in_progress'},{conclusion:'failure'},{conclusion:'cancelled'}]) assert.equal(eligibleDeploy(run,[{...jobs[0],...changed},jobs[1]],repository),false);
 for(const changed of [{head_branch:'other'},{run_attempt:2},{path:'.github/workflows/ci.yml'},{conclusion:'cancelled'},{repository:{full_name:'other/repo'}}]) assert.equal(eligibleDeploy({...run,...changed},jobs,repository),false);
 assert.equal(eligibleDeploy(run,[...jobs,{name:'Publish exact authority',conclusion:'failure'}],repository),false);
});

function inputs(state={sha},eventRunId=7) {
 return {repository,state,eventRunId,api:path=>{
  if(path.endsWith('/attempts/1/jobs?per_page=100'))return {total_count:jobs.length,jobs};
  if(path.includes('/artifacts?'))return {total_count:1,artifacts:[{name:`trunk-production-${sha}`,expired:false}]};
  if(path.startsWith('actions/workflows/'))return {workflow_runs:[run]};
  return run;
 },readProduction:async()=>({sha,ci_run_id:3})};
}
test('event and daily catch-up bind the actual current SHA and original run IDs',async()=>{
 const expected={disposition:'BUILD',sha,deploy_run_id:7,ci_run_id:3};
 assert.deepEqual(await selectSource(inputs()),expected);
 assert.deepEqual(await selectSource(inputs({sha},null)),expected);
 assert.deepEqual(await selectSource({...inputs(),state:{sha:'b'.repeat(40)}}),{disposition:'SUPERSEDED_OR_INELIGIBLE'});
 const fence={sha,generation:'g',lock_identity:'l',release_id:'r',version_vector_hash:'v'};
 assert.deepEqual(await selectSource(inputs({...fence,activation_state:'READY',activation_fence:fence},null)),{disposition:'VERIFIED_UNCHANGED'});
 assert.equal((await selectSource(inputs({...fence,generation:'next',activation_state:'READY',activation_fence:fence},null))).disposition,'BUILD');
 await assert.rejects(()=>selectSource({...inputs({sha},null),readProduction:async()=>({sha:'b'.repeat(40),ci_run_id:3})}),/A08_CURRENT_SOURCE_HOLD/);
});
test('independent A08-only runs cannot become a PHP regression baseline',async()=>{
 async function* runs(){yield {id:8,status:'completed',conclusion:'success',head_branch:'main',run_attempt:1,head_sha:sha};}
 await assert.rejects(()=>phpRepairBaseline(runs(),async()=>[{name:'Publish A08 software evidence with operations independent',conclusion:'success'}],sha,()=>true),/NIGHTLY_PHP_BASE_HOLD/);
});
test('A08 producer has no Deploy queue or heavy PHP dependency; schedule and event cancellation stay isolated',()=>{
 const deploy=readFileSync(new URL('../workflows/deploy.yml',import.meta.url),'utf8');
 const nightly=readFileSync(new URL('../workflows/nightly.yml',import.meta.url),'utf8');
 assert.doesNotMatch(deploy,/a08-evidence:/);
 const producer=nightly.split('  a08-evidence:')[1].split('  repair-scope:')[0];
 assert.doesNotMatch(producer,/needs:|trunk-deploy|full-phpunit|continue-on-error/);
 assert.match(nightly,/workflows: \[Deploy\]/);
 assert.match(nightly,/group: nightly-.*github\.event\.workflow_run\.id/);
 assert.match(nightly,/cancel-in-progress: \$\{\{ github\.event_name == 'push' \|\| github\.event\.schedule == '37 18 \* \* 0' \}\}/);
 assert.match(producer,/SOURCE_DEPLOY_RUN_ID/);
 assert.match(producer,/sourceState.*a08-current/);
});

test('rollback uses the trusted producer and streams its fenced installer into the old runtime',()=>{
 const nightly=readFileSync(new URL('../workflows/nightly.yml',import.meta.url),'utf8');
 const producer=nightly.split('  a08-evidence:')[1].split('  repair-scope:')[0];
 assert.equal((producer.match(/uses: actions\/checkout@/g)||[]).length,1);
 assert.doesNotMatch(producer,/ref: \$\{\{ env\.DEPLOY_SHA/);
 const transport=readFileSync(new URL('../../backend/scripts/deploy/seo_a08_transport.sh',import.meta.url),'utf8');
 assert.match(transport,/Platform12ActivationInstallFence\.php/);
 assert.match(transport,/a08RequestBytes/);
 assert.doesNotMatch(transport,/php scripts\/deploy\/seo_a08_install\.php/);
 const release=readFileSync(new URL('./seo-platform-12a08-release.mjs',import.meta.url),'utf8');
 assert.match(release,/fingerprint\(process\.cwd\(\), sha, scopeVersion\)/);
});

test('producer SHA is independently CI-qualified; a newer unverified main cannot install old software proof',()=>{
 const producer='b'.repeat(40);
 const ci={id:11,head_sha:producer,repository:{full_name:repository},path:'.github/workflows/ci.yml',head_branch:'main',event:'push',run_attempt:1,status:'completed',conclusion:'success'};
 const input={repository,sha:producer,checkedOutSha:producer,api:()=>({workflow_runs:[ci]})};
 assert.deepEqual(verifyProducer(input),{producer_sha:producer,ci_run_id:11});
 for(const change of [{status:'in_progress'},{conclusion:'failure'},{head_sha:sha},{head_branch:'other'},{event:'pull_request'},{run_attempt:2},{path:'.github/workflows/nightly.yml'}]) {
  assert.throws(()=>verifyProducer({...input,api:()=>({workflow_runs:[{...ci,...change}]})}),/A08_PRODUCER_CI_HOLD/);
 }
 assert.throws(()=>verifyProducer({...input,checkedOutSha:sha}),/A08_PRODUCER_IDENTITY_HOLD/);
 const nightly=readFileSync(new URL('../workflows/nightly.yml',import.meta.url),'utf8');
 const job=nightly.split('  a08-evidence:')[1].split('  repair-scope:')[0];
 assert.match(job,/ref: \$\{\{ steps\.producer\.outputs\.sha \}\}/);
 assert.ok(job.indexOf('const proof = verifyProducer') < job.indexOf('Start existing production transport'));
});

// Execute the actual pre-checkout bootstrap: it must not depend on code from
// the newer default-branch SHA whose CI may still be running.
const nightly=readFileSync(new URL('../workflows/nightly.yml',import.meta.url),'utf8');
const producerJob=nightly.split('  a08-evidence:')[1].split('  repair-scope:')[0];
const selector=producerJob.split('          script: |\n')[1].split('      - uses: actions/checkout@')[0]
 .split('\n').map(line=>line.slice(12)).join('\n');
const AsyncFunction=Object.getPrototypeOf(async function(){}).constructor;
const selectProducer=new AsyncFunction('github','context','core',selector);
const qualifiedCi=(overrides={})=>({id:11,head_sha:'b'.repeat(40),repository:{full_name:repository},path:'.github/workflows/ci.yml',
 head_branch:'main',event:'push',run_attempt:1,status:'completed',conclusion:'success',...overrides});
async function bootstrap(runs,{head='c'.repeat(40),statuses={},repo='fap-api',error=null}={}) {
 const outputs={},comparisons=[];
 await selectProducer({rest:{actions:{listWorkflowRuns:async options=>{
  assert.deepEqual(options,{owner:'fermatmind',repo,workflow_id:'ci.yml',branch:'main',event:'push',status:'success',per_page:100});
  if(error)throw error;
  return {data:{workflow_runs:runs}};
 }},repos:{compareCommits:async options=>{
  assert.equal(options.head,head);comparisons.push(options.base);
  return {data:{status:statuses[options.base]??'ahead'}};
 }}}},{repo:{owner:'fermatmind',repo},sha:head},{setOutput:(key,value)=>{outputs[key]=value;}});
 return {outputs,comparisons};
}

test('a completed older Deploy is not blocked by pending or failed CI at the new main tip',async()=>{
 const producer=qualifiedCi(),pending=qualifiedCi({head_sha:'c'.repeat(40),status:'in_progress',conclusion:null});
 for(const current of [pending,{...pending,status:'completed',conclusion:'failure'}]) {
  const result=await bootstrap([current,producer]);
  assert.deepEqual(result.outputs,{sha:producer.head_sha});
  assert.deepEqual(result.comparisons,[producer.head_sha]);
  const proof=verifyProducer({repository,sha:result.outputs.sha,checkedOutSha:producer.head_sha,api:()=>({workflow_runs:[producer]})});
  assert.equal(proof.ci_run_id,11);
  // The installer still uses the original Deploy's actual software identity.
  assert.deepEqual(await selectSource(inputs()),{disposition:'BUILD',sha,deploy_run_id:7,ci_run_id:3});
 }
});

test('queued workflow chooses a successful ancestor, not a future or divergent green commit',async()=>{
 const future=qualifiedCi({head_sha:'d'.repeat(40)}),divergent=qualifiedCi({head_sha:'e'.repeat(40)}),ancestor=qualifiedCi();
 const result=await bootstrap([future,divergent,ancestor],{statuses:{[future.head_sha]:'behind',[divergent.head_sha]:'diverged'}});
 assert.deepEqual(result.outputs,{sha:ancestor.head_sha});
 assert.equal((await bootstrap([ancestor],{head:ancestor.head_sha,statuses:{[ancestor.head_sha]:'identical'}})).outputs.sha,ancestor.head_sha);
 await assert.rejects(()=>bootstrap([future],{statuses:{[future.head_sha]:'behind'}}),/A08_PRODUCER_CI_HOLD/);
});

test('bootstrap fails closed without successful original main CI or with an unavailable inventory',async()=>{
 for(const changes of [{status:'in_progress'},{conclusion:'failure'},{head_branch:'feature'},{event:'pull_request'},{run_attempt:2},
  {path:'.github/workflows/nightly.yml'},{repository:{full_name:'other/repo'}},{head_sha:'invalid'}]) {
  await assert.rejects(()=>bootstrap([qualifiedCi(changes)]),/A08_PRODUCER_CI_HOLD/);
 }
 await assert.rejects(()=>bootstrap([]),/A08_PRODUCER_CI_HOLD/);
 await assert.rejects(()=>bootstrap([qualifiedCi()],{repo:'other'}),/A08_PRODUCER_IDENTITY_HOLD/);
 await assert.rejects(()=>bootstrap([qualifiedCi()],{head:'invalid'}),/A08_PRODUCER_IDENTITY_HOLD/);
 await assert.rejects(()=>bootstrap([],{error:new Error('inventory unavailable')}),/inventory unavailable/);
 const ci=qualifiedCi();
 assert.throws(()=>verifyProducer({repository,sha:ci.head_sha,checkedOutSha:ci.head_sha,api:()=>({workflow_runs:[ci,ci]})}),/A08_PRODUCER_CI_HOLD/);
});

test('selected producer is reverified before SSH while Deploy source, rollback and schedules retain their own identities',()=>{
 assert.ok(producerJob.indexOf('id: producer')<producerJob.indexOf('uses: actions/checkout@'));
 assert.match(producerJob,/A08_PRODUCER_SHA: \$\{\{ steps\.producer\.outputs\.sha \}\}/);
 assert.match(producerJob,/sha: process\.env\.A08_PRODUCER_SHA/);
 assert.doesNotMatch(producerJob,/sha: process\.env\.GITHUB_SHA|--verify-producer/);
 assert.match(producerJob,/checkedOutSha: execFileSync\('git', \['rev-parse', 'HEAD'\]/);
 assert.match(producerJob,/writeFileSync\('a08-producer\.json'/);
 assert.match(producerJob,/SOURCE_DEPLOY_RUN_ID: \$\{\{ github\.event\.workflow_run\.id \}\}/);
 assert.match(producerJob,/if: github\.event_name == 'workflow_run' \|\| \(github\.event_name == 'schedule' && github\.event\.schedule == '17 18 \* \* \*'\)/);
 assert.ok(producerJob.indexOf('const proof = verifyProducer')<producerJob.indexOf('Start existing production transport'));
});
