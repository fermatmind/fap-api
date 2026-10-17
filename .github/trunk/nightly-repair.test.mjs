import test from 'node:test';
import assert from 'node:assert/strict';
import {nightlyRunHistory,phpRepairBaseline} from './nightly-repair.mjs';

const head='b'.repeat(40),base='a'.repeat(40);
const run=(id,extra={})=>({id,head_sha:head,status:'completed',conclusion:'failure',head_branch:'main',run_attempt:1,...extra});
const php=(conclusion)=>[{name:'Focused PHPUnit regression and performance contracts',conclusion}];

test('PHP baseline survives more than twenty failed or PHP-skipped runs',async()=>{
 const runs=Array.from({length:50},(_,i)=>run(100-i));
 runs.push(run(1,{head_sha:base,conclusion:'success'}));
 assert.equal(await phpRepairBaseline(runs,id=>php(id===1?'success':id%2?'failure':'skipped'),head,()=>true),base);
});

test('PHP baseline lazily searches later API pages and excludes the current run',async()=>{
 const pages=[],jobIds=[];
 const history=nightlyRunHistory(async page=>{
  pages.push(page);
  if(page===1)return Array.from({length:100},(_,i)=>run(200-i));
  if(page===2)return [run(100,{head_sha:base}),run(99)];
  assert.fail('must stop after finding PHP proof');
 },200);
 assert.equal(await phpRepairBaseline(history,id=>{jobIds.push(id);return php(id===100?'success':'skipped');},head,()=>true),base);
 assert.deepEqual(pages,[1,2]);
 assert.equal(jobIds.includes(200),false);
 assert.equal(jobIds.includes(99),false);
});

test('no PHP proof still holds; green daily jobs, reruns and unrelated heads never qualify',async()=>{
 const runs=[run(5,{conclusion:'success'}),run(4,{run_attempt:2}),run(3,{head_branch:'other'}),run(2,{head_sha:'c'.repeat(40)}),run(1,{status:'cancelled'})];
 const jobs=id=>id===5?[{name:'Daily production scheduler evidence archive',conclusion:'success'},...php('skipped')]:php('success');
 const pages=[];
 const history=nightlyRunHistory(page=>{pages.push(page);return page===1?runs:[];},999);
 await assert.rejects(phpRepairBaseline(history,jobs,head,sha=>sha!== 'c'.repeat(40)),/NIGHTLY_PHP_BASE_HOLD/);
 assert.deepEqual(pages,[1]);
});

test('missing history, API errors and missing PHP job inventory fail closed',async()=>{
 for(const listRuns of [()=>undefined,()=>{throw new Error('API unavailable');}]){
  await assert.rejects(phpRepairBaseline(nightlyRunHistory(listRuns,1),()=>php('success'),head,()=>true),/INVENTORY_HOLD|API unavailable/);
 }
 await assert.rejects(phpRepairBaseline([run(1)],()=>[],head,()=>true),/NIGHTLY_PHP_BASE_HOLD/);
});
