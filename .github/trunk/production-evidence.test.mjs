import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { downloadProductionArtifact, readProductionCI, verifyProductionBinding } from './production-evidence.mjs';

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
const ciRun = () => ({id:7,head_sha:candidate,head_branch:'main',event:'push',run_attempt:1,
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
  for(const mutation of [{id:8},{head_sha:'b'.repeat(40)},{head_branch:'other'},{event:'pull_request'},
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
