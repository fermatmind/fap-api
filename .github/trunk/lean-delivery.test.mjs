import test from 'node:test';
import assert from 'node:assert/strict';
import {execFileSync,spawnSync} from 'node:child_process';
import {readFileSync,mkdtempSync,mkdirSync,writeFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {verifyProductionBinding} from './production-evidence.mjs';
import {candidateDisposition} from './admit-release.mjs';
import {selectOperations} from './impact-consumers.mjs';
import {repairDomains} from './nightly-repair.mjs';
import {scopeFor,LEGACY_SCOPE_VERSION,fingerprint} from './seo-platform-12a08-activation.mjs';
const a='a'.repeat(40),b='b'.repeat(40);
const proof=()=>({run:{id:8,run_attempt:1,head_sha:b},artifact:{name:`trunk-production-${a}`},timing:{schema_version:'fermatmind.trunk-delivery-timing.v1',sha:a,ci_run_id:7,deploy_run_id:8,deploy_run_attempt:1,production_outcome:'success',production_smoke_completed_at:'2026-10-06T01:00:00Z'},ci:{id:7,head_sha:a,head_branch:'main',event:'push',status:'completed',conclusion:'success',run_attempt:1},receipt:{schema_version:'fermatmind.trunk-validation.v1',sha:a,ci_run_id:'7',result:'success',classification:{deploy:true}},job:{conclusion:'success'}});
test('candidate binding rejects displayed identity, missing smoke, failed CI and rollback; accepts a proven failed operations tail',()=>{
 assert.equal(verifyProductionBinding(proof()),a);
 for(const change of [p=>p.timing.sha=b,p=>p.timing.production_smoke_completed_at=null,p=>p.ci.conclusion='failure',p=>p.receipt.classification.deploy=false,p=>p.timing.deploy_run_id=9,p=>p.timing.production_outcome='failure',p=>p.outcome={revision:a,status:'success',phase:'complete',exit_code:0,rollback:'restored'}]){let p=proof();change(p);assert.throws(()=>verifyProductionBinding(p));}
 const tail=proof();tail.job.conclusion='failure';assert.equal(verifyProductionBinding(tail),a);delete tail.timing.production_outcome;assert.throws(()=>verifyProductionBinding(tail));
});
test('admission recognizes already accepted/stale candidates and rejects divergence',()=>{
 assert.equal(candidateDisposition(a,a,()=>false),'already_accepted');assert.equal(candidateDisposition(a,b,(x,y)=>x===a&&y===b),'stale_accepted_ancestor');assert.equal(candidateDisposition(b,a,(x,y)=>x===a&&y===b),'forward_candidate');assert.throws(()=>candidateDisposition(a,b,()=>false),/NON_FORWARD/);
});
test('real publisher consumer graph keeps independent Article/Ops quiet, selects one family, and preserves shared unions',()=>{
 const quiet=selectOperations(['backend/app/Http/Controllers/API/V0_5/Cms/ArticleController.php']);for(const key of ['big5_private_publish','riasec_private_publish','enneagram_private_publish','eq60_private_publish','career_cache'])assert.equal(quiet[key],false,key);
 const one=selectOperations(['backend/content_packs/ENNEAGRAM/v2/registry/traits.json']);assert.equal(one.enneagram_private_publish,true);assert.equal(one.big5_private_publish,false);assert.equal(one.riasec_private_publish,false);assert.equal(one.eq60_private_publish,false);
 const all=selectOperations(['backend/app/Services/Content/ContentPackV2Resolver.php']);for(const key of ['big5','riasec','enneagram','eq60'])assert.equal(all[key+'_private_publish'],true);
 const unknown=selectOperations(['backend/app/Services/UnresolvedNewService.php']);assert.ok(Object.entries(unknown).filter(([k])=>!['content_test_files','test_modes'].includes(k)).every(([,v])=>v));
});
test('Nightly repair selects actual changed PHP classes and leaves independent heavy domains not applicable',()=>{
 const plan=repairDomains(['backend/tests/Feature/SeoIntel/SeoPlatform12A08ActivationEvidenceTest.php']);assert.ok(plan.php_required);assert.ok(plan.php_files.includes('tests/Feature/SeoIntel/SeoPlatform12A08ActivationEvidenceTest.php'));for(const key of ['authority','dependency','security'])assert.equal(plan[key],false);
});
test('legacy scope exactly retains old Filament Ops and tooling; new presentation remains independently testable',()=>{
 assert.deepEqual(scopeFor('backend/app/Filament/Ops/Foo.php',LEGACY_SCOPE_VERSION),['public']);assert.deepEqual(scopeFor('.github/trunk/classify-paths.test.mjs',LEGACY_SCOPE_VERSION),['public']);assert.deepEqual(scopeFor('.github/trunk/classify-paths.test.mjs'),[]);
});
// Execute the actual prepared-candidate guard shell with local fixture I/O.
test('prepared guard rejects active, wrong SHA and cross-environment candidates before migration or activation',()=>{
 const source=readFileSync(new URL('../../deploy.php',import.meta.url),'utf8'),start=source.indexOf("task('guard:prepared-candidate'"),part=source.slice(start,source.indexOf("task('deploy:prepared'",start));
 const shell=part.split("run(<<<'BASH'\n")[1].split('\nBASH);')[0],root=mkdtempSync(tmpdir()+'/prepared-');
 try{
  mkdirSync(root+'/releases/candidate/backend/vendor',{recursive:true});writeFileSync(root+'/releases/candidate/REVISION',a);writeFileSync(root+'/releases/candidate/backend/.env','APP_ENV=staging');writeFileSync(root+'/releases/candidate/backend/vendor/autoload.php','');mkdirSync(root+'/releases/previous');execFileSync('ln',['-s',root+'/releases/previous',root+'/current']);
  const fake=root+'/platform';writeFileSync(fake,'#!/bin/sh\nexit 0\n',{mode:0o700});
  const run=(php=fake)=>spawnSync('bash',['-c',shell.replaceAll('{{release_path}}',root+'/releases/candidate').replaceAll('{{deploy_path}}',root).replaceAll('{{revision}}',a).replaceAll('{{bin/composer}}',fake).replaceAll('{{bin/php}}',php)],{encoding:'utf8'}).status;
  assert.equal(run(),0);writeFileSync(root+'/releases/candidate/REVISION',b);assert.notEqual(run(),0);writeFileSync(root+'/releases/candidate/REVISION',a);
  const wrong=root+'/wrong-env';writeFileSync(wrong,'#!/bin/sh\nexit 1\n',{mode:0o700});assert.notEqual(run(wrong),0);
  rmSync(root+'/current');execFileSync('ln',['-s',root+'/releases/candidate',root+'/current']);assert.notEqual(run(),0);
  assert.ok(source.indexOf("'guard:prepared-candidate'",start)<source.indexOf("'artisan:migrate'",start));
 }finally{rmSync(root,{recursive:true,force:true});}
});

test('feature modes follow actual consumers and shared inputs, avoiding a duplicate static matrix',()=>{assert.deepEqual(selectOperations(['.github/trunk/seo-platform-12a08-activation.mjs']).test_modes,['legacy']);assert.deepEqual(selectOperations(['backend/app/Services/UnresolvedNewService.php']).test_modes,['legacy','v2']);assert.deepEqual(selectOperations(['backend/tests/Feature/V0_3/MbtiReportHttpContractRegressionTest.php']).test_modes,['legacy','v2']);});
