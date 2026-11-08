import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { downloadProductionArtifact, readProductionCI, verifyProductionBinding, recoverProductionSteps } from './production-evidence.mjs';

const bytes = Buffer.from('immutable artifact fixture');
const artifact = {id: 1, expired: false, digest: `sha256:${createHash('sha256').update(bytes).digest('hex')}`};
const httpError = status => Object.assign(new Error('GitHub read failed'), {stderr: Buffer.from(`gh: HTTP ${status}`)});

test('transient artifact errors retry only the read and verify the final bytes', () => {
  let calls = 0; const waits = [];
  const actual = downloadProductionArtifact('owner/repo', artifact, {
    request: () => { if (++calls < 3) throw httpError(503); return bytes; },
    wait: delay => waits.push(delay),
  });
  assert.equal(actual, bytes);
  assert.equal(calls, 3);
  assert.deepEqual(waits, [1000, 2000]);
});

test('persistent service failure is bounded; auth and missing evidence fail immediately', () => {
  for (const [status, expected] of [[503, 3], [502, 3], [429, 3], [401, 1], [403, 1], [404, 1]]) {
    let calls = 0;
    assert.throws(() => downloadProductionArtifact('owner/repo', artifact, {
      request: () => { calls++; throw httpError(status); }, wait: () => {},
    }), /GitHub read failed/);
    assert.equal(calls, expected);
  }
});

test('expired or malformed authority never downloads; corrupt bytes never retry', () => {
  for (const invalid of [{...artifact, expired: true}, {...artifact, digest: 'invalid'}]) {
    assert.throws(() => downloadProductionArtifact('owner/repo', invalid, {request: () => assert.fail('must not download')}), /Invalid production artifact digest/);
  }
  let calls = 0;
  assert.throws(() => downloadProductionArtifact('owner/repo', artifact, {
    request: () => { calls++; return Buffer.from('corrupt'); }, wait: () => assert.fail('must not retry corruption'),
  }), /digest mismatch/);
  assert.equal(calls, 1);
});

const candidate = 'a'.repeat(40);
const ciRun = () => ({id:7,path:'.github/workflows/ci.yml',head_sha:candidate,head_branch:'main',event:'push',run_attempt:1,
  status:'completed',conclusion:'success',created_at:'2026-10-22T13:48:40Z'});

test('a receipt-bound CI is read by its exact run ID without consulting the run list', () => {
  const timing = {schema_version:'fermatmind.trunk-delivery-timing.v1',sha:candidate,ci_run_id:'7',
    deploy_run_id:'8',deploy_run_attempt:1,production_outcome:'success',production_smoke_completed_at:'2026-10-22T14:43:47Z'};
  const ci = readProductionCI(candidate,timing,path=>{
    assert.equal(path,'actions/runs/7');
    return ciRun();
  });
  assert.equal(verifyProductionBinding({run:{id:8,run_attempt:1,head_sha:'b'.repeat(40)},
    artifact:{name:`trunk-production-${candidate}`},timing,ci,job:{conclusion:'success'},
    receipt:{schema_version:'fermatmind.trunk-validation.v1',sha:candidate,ci_run_id:7,result:'success',classification:{deploy:true}}}),candidate);
});

test('the exact CI lookup rejects wrong identity, SHA, event, branch, state and attempt', () => {
  for(const mutation of [{id:8},{path:'.github/workflows/nightly.yml'},{head_sha:'b'.repeat(40)},{head_branch:'other'},{event:'pull_request'},
    {status:'in_progress'},{conclusion:'failure'},{run_attempt:2}]) {
    assert.throws(()=>readProductionCI(candidate,{ci_run_id:7},path=>{
      assert.equal(path,'actions/runs/7');return {...ciRun(),...mutation};
    }),/Invalid production CI binding/);
  }
  for(const id of ['',0,-1,'../7','7?head_sha=other']) {
    assert.throws(()=>readProductionCI(candidate,{ci_run_id:id},()=>assert.fail('must not request an invalid run ID')),/Invalid production CI binding/);
  }
  assert.throws(()=>readProductionCI(candidate,{ci_run_id:7},path=>{
    assert.equal(path,'actions/runs/7');throw new Error('exact run unavailable');
  }),/exact run unavailable/);
});

test('legacy timing uses one exact-SHA and timestamp match and still rejects missing or ambiguous evidence', () => {
  const timing={pushed_at:ciRun().created_at};
  assert.equal(readProductionCI(candidate,timing,()=>({workflow_runs:[
    {...ciRun(),id:9,head_sha:'b'.repeat(40)},ciRun(),{...ciRun(),id:10,created_at:'2026-10-22T13:48:41Z'},
  ]})).id,7);
  for(const workflow_runs of [[],[ciRun(),{...ciRun(),id:8}]]) {
    assert.throws(()=>readProductionCI(candidate,timing,()=>({workflow_runs})),/Ambiguous production CI binding/);
  }
});

const missingStepsJob = {name:'Production exact-SHA activation, smoke, and LKG fallback',status:'completed',conclusion:'success',steps:[]};
const activationLines = [
  `Complete job name: ${missingStepsJob.name}`,
  '##[group]task deploy:symlink',
  '##[group]task queue:reload-workers',
  '##[group]task healthcheck:public',
  '##[group]task healthcheck:auth-guest-contract',
  '##[group]task healthcheck:scale-lookup',
  '[production] info successfully deployed!',
  '##[group]task cache:accept-public-projection',
];
const activationLog = lines => lines.map(line=>`2026-11-08T03:22:05.1234567Z ${line}`).join('\n');

test('missing GitHub steps require the same successful job log with activation and ordered smoke', () => {
  const recovered = recoverProductionSteps(missingStepsJob,activationLog(activationLines));
  assert.deepEqual(recovered.steps,[{name:'Deploy once and automatically restore LKG after committed smoke failure',conclusion:'success'}]);
  assert.deepEqual(missingStepsJob.steps,[]);
});

test('log recovery rejects missing, duplicate, reordered, failed and nonproduction activation', () => {
  for(let i=0;i<activationLines.length;i++) {
    assert.throws(()=>recoverProductionSteps(missingStepsJob,activationLog(activationLines.filter((_,j)=>j!==i))));
    assert.throws(()=>recoverProductionSteps(missingStepsJob,activationLog([...activationLines,activationLines[i]])));
  }
  const reordered=[...activationLines];[reordered[2],reordered[3]]=[reordered[3],reordered[2]];
  for(const lines of [[],reordered,[...activationLines,'##[error]late failure'],
    ['Persist push-to-production timing receipt','production_outcome=success']]) {
    assert.throws(()=>recoverProductionSteps(missingStepsJob,activationLog(lines)));
  }
  for(const mutation of [{name:'Staging exact-SHA deploy and smoke'},{status:'in_progress'},
    {conclusion:'failure'},{conclusion:'skipped'},{steps:undefined},
    {steps:[{name:'Deploy once and automatically restore LKG after committed smoke failure',conclusion:'skipped'}]}]) {
    assert.throws(()=>recoverProductionSteps({...missingStepsJob,...mutation},activationLog(activationLines)),/Invalid missing-step recovery/);
  }
});
