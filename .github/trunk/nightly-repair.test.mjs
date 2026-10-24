import test from 'node:test';
import assert from 'node:assert/strict';
import {nightlyRunHistory,phpRepairBaseline,repairDomains} from './nightly-repair.mjs';

import {mkdtempSync,writeFileSync,rmSync,readFileSync,chmodSync,mkdirSync} from 'node:fs';
import {execFileSync,spawnSync} from 'node:child_process';
import {tmpdir} from 'node:os';
import {join} from 'node:path';

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

test('JS/TS scan inputs and configuration select security without broad PHP checks',()=>{
 const root=mkdtempSync(join(tmpdir(),'nightly-scan-inputs-'));
 try {
  execFileSync('git',['init','-q'],{cwd:root});writeFileSync(join(root,'README.md'),'fixture');execFileSync('git',['add','README.md'],{cwd:root});
  const workflowSource=readFileSync(new URL('../workflows/nightly.yml',import.meta.url),'utf8');
  const push=workflowSource.split('    paths:\n')[1].split('  schedule:')[0];
  writeFileSync(join(root,'.git/info/exclude'),[...push.matchAll(/- '([^']+)'/g)].map(match=>match[1]).join('\n'));
  const triggered=path=>spawnSync('git',['-c','core.excludesFile=/dev/null','check-ignore','--no-index',path],{cwd:root}).status===0;
  const plan=paths=>repairDomains(paths,root);
  for(const path of ['.github/trunk/impact-consumers.mjs','.github/trunk/eq-new-source-online-qa.mjs',
   '.github/trunk/public-content-53-online-qa.mjs','.github/trunk/iq-public-article-online-qa.test.mjs',
   'new/unknown-name.ts','index.js','test.cjs','test.jsx','view.tsx','module.mts','module.cts','page.html','component.vue',
   '.github/codeql/unknown-config.yml','queries/custom.ql','queries/model.qll','queries/suite.qls','package.json','nested/package-lock.json','pnpm-lock.yaml','tsconfig.build.json','.gitignore']) {
   const result=plan([path]);assert.ok(triggered(path),path+': push trigger');assert.equal(result.security,true,path);assert.equal(result.semgrep,false,path);
   for(const key of ['php_required','authority','dependency'])assert.equal(result[key],false,path+': '+key);
  }
  for(const path of ['docs/guide.md','backend/content_assets/package/assets.json','backend/lang/en/messages.php','README.md']) {
   const result=plan([path]);assert.equal(triggered(path),false,path+': push trigger');assert.equal(result.security,false,path);
   assert.equal(result.php_required,false,path);assert.equal(result.dependency,false,path);
  }
  for(const path of ['.github/workflows/nightly.yml','.semgrepignore','backend/.semgrep.yml','backend/.semgrep.yaml','backend/app/Policies/Unknown.php']) {
   const result=plan([path]);assert.equal(result.security,true,path);assert.equal(result.semgrep,true,path);
  }
  const workflow=plan(['.github/workflows/ci.yml']);assert.equal(workflow.security,true);assert.equal(workflow.workflow,true);assert.equal(workflow.php_required,false);
  // A PHP-skipped repair must not make every later PHP test re-scan old JS.
  const later=repairDomains(['old.mjs','backend/tests/Unit/ChangedTest.php'],root,[],['backend/tests/Unit/ChangedTest.php']);
  assert.equal(later.security,false);assert.equal(later.php_required,true);
 } finally {rmSync(root,{recursive:true,force:true});}
});

test('push scan selection uses the actual diff and rejects missing or non-forward push baselines',()=>{
 const root=mkdtempSync(join(tmpdir(),'nightly-push-scan-'));
 try {
  const git=args=>execFileSync('git',args,{cwd:root,encoding:'utf8'}).trim();
  git(['init','-q']);writeFileSync(join(root,'README.md'),'fixture');git(['add','README.md']);
  const commit=()=>git(['-c','user.name=Fixture','-c','user.email=fixture@example.invalid','commit','-qm','fixture']);
  commit();const base=git(['rev-parse','HEAD']);writeFileSync(join(root,'new.mjs'),'export const value=1;');git(['add','new.mjs']);commit();const head=git(['rev-parse','HEAD']);
  mkdirSync(join(root,'bin'));
  const gh=join(root,'bin/gh');
  writeFileSync(gh,`#!${process.execPath}
const path=process.argv.at(-1);
if(process.env.FIXTURE_NO_PHP_HISTORY==='true')throw new Error('Unexpected PHP history lookup');
process.stdout.write(JSON.stringify(path.includes('/jobs?')?{total_count:1,jobs:[{name:'Focused PHPUnit regression and performance contracts',conclusion:'success'}]}:{workflow_runs:[{id:2,head_sha:'${base}',status:'completed',conclusion:'success',head_branch:'main',run_attempt:1}]}));
`);chmodSync(gh,0o755);
  const script=new URL('./nightly-repair.mjs',import.meta.url).pathname;
  const execute=(before,sha=head,refuseHistory=true)=>spawnSync(process.execPath,[script],{cwd:root,encoding:'utf8',timeout:10000,
   env:{...process.env,PATH:join(root,'bin')+':'+process.env.PATH,GITHUB_SHA:sha,PUSH_BEFORE:before,GITHUB_RUN_ID:'1',GITHUB_REPOSITORY:'fixture/repo',GITHUB_OUTPUT:join(root,'outputs'),FIXTURE_NO_PHP_HISTORY:refuseHistory?'true':'false'}});
  const accepted=execute(base);assert.equal(accepted.status,0,accepted.stderr);
  const receipt=JSON.parse(readFileSync(join(root,'nightly-repair.json')));
  assert.equal(receipt.security,true);assert.deepEqual(receipt.scan_paths,['new.mjs']);
  assert.equal(receipt.base,base);assert.equal(receipt.php_required,false);assert.equal(receipt.authority,false);
  assert.match(readFileSync(join(root,'outputs'),'utf8'),/security=true/);
  mkdirSync(join(root,'backend/tests/Unit'),{recursive:true});writeFileSync(join(root,'backend/tests/Unit/ChangedTest.php'),'<?php class ChangedTest {}');
  git(['add','backend/tests/Unit/ChangedTest.php']);commit();const phpHead=git(['rev-parse','HEAD']);
  const repaired=execute(head,phpHead,false);assert.equal(repaired.status,0,repaired.stderr);
  const phpReceipt=JSON.parse(readFileSync(join(root,'nightly-repair.json')));
  assert.equal(phpReceipt.base,base);assert.equal(phpReceipt.push_base,head);
  assert.ok(phpReceipt.paths.includes('new.mjs'));assert.equal(phpReceipt.security,false);
  assert.deepEqual(phpReceipt.php_files,['tests/Unit/ChangedTest.php']);
  const missingProof=execute(head,phpHead);assert.notEqual(missingProof.status,0);assert.match(missingProof.stderr,/Unexpected PHP history lookup/);
  for(const [before,sha] of [['',head],['0'.repeat(40),head],['unknown',head],[head,base]]) {
   const failed=execute(before,sha);assert.notEqual(failed.status,0);assert.match(failed.stderr,/NIGHTLY_(?:REPAIR|PUSH)_BASE_HOLD/);
  }
 } finally {rmSync(root,{recursive:true,force:true});}
});
