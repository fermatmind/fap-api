import test from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync,mkdtempSync,mkdirSync,writeFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {resolve} from 'node:path';
import {candidate,execution,invoke,packagePath,assertApi,packageSha,sanitizeBindingDiagnostics} from './reviewed-science-pages.mjs';
import {classifyPaths} from './classify-paths.mjs';
import {requiredSeconds} from './production-release-budget.mjs';
const root=resolve(import.meta.dirname,'../../backend');
const env={SCIENCE_PUBLISH_ENVIRONMENT:'staging',DEPLOY_PATH:'/srv/example',DEPLOY_USER:'deploy',DEPLOY_HOST:'example.invalid',DEPLOY_PORT:'22',
  DEPLOY_SHA:'a'.repeat(40),GITHUB_RUN_ID:'123',GITHUB_RUN_ATTEMPT:'1',CONTENT_PROMOTION_AUTOMATION_KEY:'x'.repeat(32),DEPLOY_IDENTITY_FILE_STG:'/tmp/example-key'};
test('fixed two-locale candidate is pinned and changed bytes fail closed',()=>{
  assert.equal(candidate(root).candidates.length,2);
  const temp=mkdtempSync(resolve(tmpdir(),'science-package-'));
  try {const target=resolve(temp,packagePath);mkdirSync(resolve(target,'..'),{recursive:true});writeFileSync(target,readFileSync(resolve(root,packagePath))+' ');assert.throws(()=>candidate(temp),/HASH_INVALID/);}
  finally {rmSync(temp,{recursive:true});}
});
test('only the exact publication file selects writes; implementation corrections select checks',()=>{
  const selected=classifyPaths(['backend/'+packagePath]);
  assert.equal(selected.operations.reviewed_science_pages_publish,true);
  assert.equal(selected.operations.publisher_required,false);
  for(const path of ['backend/app/Services/ContentPages/ReviewedSciencePageRevision.php','.github/trunk/reviewed-science-pages.mjs','backend/scripts/deploy/run_reviewed_science_pages.php']) {
    const flags=classifyPaths([path]);assert.equal(flags.operations.reviewed_science_pages_checks,true);assert.equal(flags.operations.reviewed_science_pages_publish,false);
  }
  assert.equal(requiredSeconds(30,{reviewed_science_pages_publish:true})-requiredSeconds(30,{}),1500);
});
test('transport binds the workflow identity, environment and exact candidate without shell input',()=>{
  const ex=execution(env);assert.equal(ex.binding.package_sha256,packageSha);assert.equal(ex.binding.environment,'staging');
  assert.ok(ex.args.includes('StrictHostKeyChecking=yes'));assert.ok(ex.args.includes('/tmp/example-key'));
  for(const patch of [{DEPLOY_SHA:'bad'},{DEPLOY_HOST:'x; touch y'},{GITHUB_RUN_ATTEMPT:'2'},{CONTENT_PROMOTION_AUTOMATION_KEY:''},{SCIENCE_PUBLISH_ENVIRONMENT:'other'}]) assert.throws(()=>execution({...env,...patch}));
  assert.notEqual(ex.signature,execution({...env,SCIENCE_PUBLISH_ENVIRONMENT:'production'}).signature);
});
test('native responses must match the exact run and recovery outcome',()=>{
  const ex=execution(env);const ok={ok:true,schema:'science.reviewed_pages.delivery.v1',mode:'publish',binding:ex.binding,published_count:2,sanitized:true};
  const run=value=>()=>({status:0,stdout:JSON.stringify(value)});
  assert.equal(invoke(ex,'publish',run(ok)).published_count,2);
  for(const patch of [{binding:{...ex.binding,source_commit:'b'.repeat(40)}},{published_count:1},{sanitized:false}]) assert.throws(()=>invoke(ex,'publish',run({...ok,...patch})));
  assert.throws(()=>invoke(ex,'recover',run({...ok,mode:'recover',restored:false,recovery_status:'restored'})));
  assert.equal(invoke(ex,'recover',run({...ok,mode:'recover',restored:true,recovery_status:'restored'})).restored,true);
});
test('public readback requires exact body, FAQ and unchanged protected fields',()=>{
  const expected={content_md:'Reviewed text',faq_items:[{question:'Q',answer:'A'}],is_indexable:false,published_at:'2026-06-09',canonical_path:'/item-design-notes',schema_enabled:false};
  assert.doesNotThrow(()=>assertApi(expected,{ok:true,page:expected}));
  for(const patch of [{content_md:'Old text'},{faq_items:[]},{is_indexable:true},{published_at:'2026-11-08'},{canonical_path:'/other'},{schema_enabled:true}]) assert.throws(()=>assertApi(expected,{ok:true,page:{...expected,...patch}}));
});

test('failed native execution retains a bounded rejection code without raw stderr',()=>{
  const ex=execution(env);
  assert.throws(()=>invoke(ex,'publish',()=>({status:1,stdout:JSON.stringify({ok:false,error_code:'existing_science_working_revision_missing'})})),/existing_science_working_revision_missing/);
  assert.throws(()=>invoke(ex,'publish',()=>({status:1,stdout:'',stderr:'sudo: not allowed to execute private command'})),/SCIENCE_TRANSPORT_SUDO_DENIED/);
  assert.throws(()=>invoke(ex,'publish',()=>({status:1,stdout:JSON.stringify({ok:false,error_code:'/private/path?secret=value'})})),/SCIENCE_NATIVE_OPERATION_FAILED/);
});

test('binding diagnostics expose only fixed locale predicates and bounded enums',()=>{
  const raw={capture:'after_failure_recovery',private_path:'/private/value',pages:{
    en:{page_present:true,topology:'unlinked_translation',translation_status:'published',revision_status:'published',source_found_in_org0:false,
      pair_group_matches:true,row_source_hash_valid:true,row_source_hash:'private-hash',
      source_group_matches:'not_evaluated',source_slug_matches:'/private/value',source_id:999,content_md:'private'},
    'zh-CN':{page_present:true,topology:'source',translation_status:'source'},fr:{page_present:true}}};
  const expected={capture:'after_failure_recovery',pages:{'zh-CN':{page_present:true,topology:'source',translation_status:'source'},
    en:{page_present:true,source_found_in_org0:false,source_group_matches:'not_evaluated',pair_group_matches:true,row_source_hash_valid:true,
      topology:'unlinked_translation',translation_status:'published',revision_status:'published'}}};
  assert.deepEqual(sanitizeBindingDiagnostics(raw),expected);
  assert.equal(sanitizeBindingDiagnostics({capture:'other',pages:raw.pages}),undefined);
  const ex=execution(env);
  assert.throws(()=>invoke(ex,'publish',()=>({status:1,stdout:JSON.stringify({ok:false,
    error_code:'existing_science_source_binding_invalid',binding_diagnostics:raw})})),error=>{
      assert.deepEqual(error.bindingDiagnostics,expected);return true;
    });
});
