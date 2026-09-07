import assert from 'node:assert/strict';
import test from 'node:test';
import {mkdtempSync,writeFileSync,readFileSync,rmSync,mkdirSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {execFileSync} from 'node:child_process';
import {fingerprint,scopeFor,mayCarry,MISSIONS,scopedReceipt,CHECKS} from './seo-platform-12a08-activation.mjs';
import {verifyState} from './seo-platform-12a08-release.mjs';
import {classifyPaths} from './classify-paths.mjs';
test('explicit shared versus mission dependencies exclude ordinary copy, retain identities and authority',()=>{
 for(const path of ['backend/routes/api.php','backend/composer.lock','backend/app/Http/Middleware/Auth.php','backend/content_assets/personality_public/current/manifest.json']) assert.deepEqual(scopeFor(path),['public']);
 assert.deepEqual(scopeFor('backend/docs/example.md'),[]);
 assert.deepEqual(scopeFor('backend/content_assets/personality_public/current/page/zh-CN.md'),[]);
 assert.deepEqual(scopeFor('backend/app/Services/SeoCouncil/Platform12/Evaluation/Platform12DailySecurityDriftEvaluator.php'),[MISSIONS[2]]);
 const result=classifyPaths(['backend/app/Services/SeoCouncil/Platform12/Platform12RuntimeControl.php','.github/workflows/deploy.yml']);
 assert.equal(result.operations.a08_gate_only,true);
 assert.equal(classifyPaths(['backend/app/Services/SeoCouncil/Platform12/Platform12RuntimeControl.php','backend/app/Services/Payments/Order.php']).operations.a08_gate_only,false);
});
test('carry requires real Git ancestry, both fingerprints and equal vectors; v1 never authorizes',()=>{
 const root=mkdtempSync(`${tmpdir()}/a08-git-`); const git=(...args)=>execFileSync('git',args,{cwd:root}).toString().trim();
 try {
  git('init','-q');git('config','user.email','test@example.test');git('config','user.name','Test');
  mkdirSync(`${root}/backend/routes`,{recursive:true});writeFileSync(`${root}/backend/routes/api.php`,'a');git('add','.');git('commit','-qm','initial');
  const source=git('rev-parse','HEAD'),fp=fingerprint(root,source),vector={policy:'x'};
  const manifest={schema_version:'seo.platform12_a08_activation.v2',repository:'fermatmind/fap-api',bound_production_sha:source,validation:{public_checks:{fingerprint:fp.public}},missions:{[MISSIONS[0]]:{checks:{fingerprint:fp[MISSIONS[0]]}}},runtime:{version_vector:vector}};
  writeFileSync(`${root}/README.md`,'copy');git('add','.');git('commit','-qm','copy');
  const candidate={production_sha:git('rev-parse','HEAD'),version_vector:vector};
  assert.equal(mayCarry(manifest,candidate,MISSIONS[0],root),true);
  assert.equal(mayCarry({...manifest,schema_version:'seo.platform12_a08_activation.v1'},candidate,MISSIONS[0],root),false);
  assert.equal(mayCarry(manifest,{...candidate,version_vector:{policy:'changed'}},MISSIONS[0],root),false);
  writeFileSync(`${root}/backend/routes/api.php`,'permissions changed');git('add','.');git('commit','-qm','auth');
  assert.equal(mayCarry(manifest,{...candidate,production_sha:git('rev-parse','HEAD')},MISSIONS[0],root),false);
  assert.equal(mayCarry({...manifest,bound_production_sha:git('rev-parse','HEAD')},candidate,MISSIONS[0],root),false);
 } finally {rmSync(root,{recursive:true,force:true});}
});
test('offline receipts cannot omit test scope or manufacture real source evidence',()=>{
 assert.throws(()=>scopedReceipt('<testcase name="unrelated"/>',execFileSync('git',['rev-parse','HEAD']).toString().trim()),/COVERAGE|RESULTS/);
 assert.throws(()=>scopedReceipt('<testcase/><failure/>','a'.repeat(40)),/RESULTS/);
});
test('pause, generation, pending counts and exact SHA must be preserved',()=>{
 const state={gate_only:true,sha:'a'.repeat(40),paused:true,generation:'x',counts:{runs:0},selected_missions:[],business_guards_closed:true,operations_readonly:true};
 assert.equal(verifyState(state,state,state.sha),true);
 for(const extra of [{generation:'y'},{paused:false},{counts:{runs:1}},{selected_missions:[MISSIONS[0]]},{sha:'b'.repeat(40)}]) assert.throws(()=>verifyState(state,{...state,...extra},state.sha));
});
test('existing workflows publish completed scoped evidence without runtime operations',()=>{
 const deploy=readFileSync(new URL('../workflows/deploy.yml',import.meta.url),'utf8');
 assert.doesNotMatch(deploy,/workflow_dispatch:|seo:council-runtime (?:resume|pause)|seo:council-scheduled --acceptance/);
 assert.match(deploy,/needs: \[policy, staging, production\]/);
 assert.match(deploy,/a08_gate_only != true/);
 assert.match(deploy,/seo-council-a08-activation-\$\{\{/);
 const ci=readFileSync(new URL('../workflows/ci.yml',import.meta.url),'utf8');
 assert.match(ci,/--log-junit=/);assert.match(ci,/scoped-receipt/);
});
test('Nightly high-risk or unknown failures cannot hide behind daily or unrelated scoped tests',async()=>{
 const {assessNightly}=await import('./seo-platform-12a08-release.mjs');
 const run={id:1,head_sha:'a'.repeat(40)};
 const full=[{name:'Full PHPUnit regression and performance contracts',conclusion:'failure'}];
 assert.throws(()=>assessNightly(run,[{name:'CodeQL and Semgrep security scan',conclusion:'failure'}],'',{}),/HIGH_RISK/);
 assert.throws(()=>assessNightly(run,full,'FAILED  Test at tests/Feature/UnknownTest.php:12',{covered_classes:[]}),/UNKNOWN/);
 const result=assessNightly(run,full,'FAILED  Test at tests/Feature/PermissionTest.php:12',{sha:'b'.repeat(40),covered_classes:['Tests\\Feature\\PermissionTest']});
 assert.equal(result.disposition,'CURRENT_CANDIDATE_FOCUSED_REVALIDATION');
 assert.equal(result.check_scope,'weekly_full_checks');
});
test('Current package body digests may change while schema, authority and identity stay bound',async()=>{
 const {contentIdentity}=await import('./seo-platform-12a08-activation.mjs');
 const page={schema_version:'v1',identity:{slug:'one'},authority:'repository',blocks:[{text:'before'}]};
 assert.deepEqual(contentIdentity(page),contentIdentity({...page,blocks:[{text:'after'}]}));
 for(const change of [{schema_version:'v2'},{authority:'database'},{identity:{slug:'two'}}]) assert.notDeepEqual(contentIdentity(page),contentIdentity({...page,...change}));
 const manifest={aggregate_sha256:'old',schema_version:'v1',files:[{path:'one',canonical_slug:'one',sha256:'old',bytes:1}],set_hashes:{slug_set_sha256:'identity',source_semantic_aggregate_sha256:'old'}};
 const body=structuredClone(manifest);body.aggregate_sha256='new';body.files[0].sha256='new';body.files[0].bytes=2;body.set_hashes.source_semantic_aggregate_sha256='new';
 assert.deepEqual(contentIdentity(manifest,true),contentIdentity(body,true));body.files[0].canonical_slug='two';assert.notDeepEqual(contentIdentity(manifest,true),contentIdentity(body,true));
});
test('staging read-only transport uses the existing host identity instead of the repository agent key',()=>{
 const root=mkdtempSync(`${tmpdir()}/a08-ssh-`);
 try {
  const ssh=`#!/bin/sh\nfound=false\nfor arg do test "$arg" != fixture-host-key || found=true; done\n$found || exit 73\ncat >/dev/null\nprintf '{}\\n'\n`;
  writeFileSync(`${root}/ssh`,ssh,{mode:0o755});
  execFileSync('bash',['backend/scripts/deploy/seo_a08_transport.sh','state',`${root}/state.json`],{env:{...process.env,PATH:`${root}:${process.env.PATH}`,TARGET:'staging',DEPLOY_IDENTITY_FILE_STG:'fixture-host-key',DEPLOY_PATH:'/fixture',DEPLOY_PORT:'22',DEPLOY_USER:'fixture',DEPLOY_HOST:'example.test',A08_GATE_ONLY:'true'}});
 }finally{rmSync(root,{recursive:true,force:true});}
});
