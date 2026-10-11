import test from 'node:test';
import assert from 'node:assert/strict';
import { resolve } from 'node:path';
import { readFileSync } from 'node:fs';
import { fileURLToPath } from 'node:url';
import { inspectPackage, readCandidateRows, isPublic53OnlyPromotionRegistration, isPublic53OnlyContextFactoryChange } from './public-content-53-package.mjs';
import { buildExecution, publish, recoveryFailure } from './public-content-53-publish.mjs';
import { assertDom, pathFor } from './public-content-53-online-qa.mjs';
const root=fileURLToPath(new URL('../../backend',import.meta.url));
test('fixed reviewed package covers exactly the existing native identities',()=>{
 const binding=inspectPackage(root);const rows=readCandidateRows(root).candidates;
 assert.equal(binding.expected_row_count,50);assert.equal(rows.length,50);assert.equal(new Set(rows.map(x=>x.page_id)).size,50);
 assert.equal(rows.filter(x=>x.kind==='article').length,33);assert.equal(rows.filter(x=>x.kind==='page').length,11);
 assert.ok(rows.every(x=>['en','zh-CN'].includes(x.identity.locale)));assert.equal(rows.filter(x=>x.kind==='profile').length,2);
});
test('public-only registration proof rejects unrelated policy or canonical changes',()=>{
 const config=readFileSync(resolve(root,'config/content_promotion.php'),'utf8');const base=config.replace("    'public_content_53_environment' => env('PUBLIC_CONTENT_53_ENVIRONMENT', 'production'),\n",'').replace("        'content_assets/public_content_53',\n",'').replace(", 'PUBLIC-CONTENT-53' => 'audit_compatible'",'');
 assert.equal(isPublic53OnlyPromotionRegistration(base,config),true);
 const legacyConfig=config.replace("    'public_content_53_environment' => env('PUBLIC_CONTENT_53_ENVIRONMENT', 'production'),\n",'');
 assert.equal(isPublic53OnlyPromotionRegistration(legacyConfig,config),true);
 assert.equal(isPublic53OnlyPromotionRegistration(legacyConfig,config.replace("'W2'","'W22'")),false);
 assert.equal(isPublic53OnlyPromotionRegistration(base,config.replace("'W2'", "'W22'")),false);
 const factory=readFileSync(resolve(root,'app/Services/ContentPromotion/PromotionContextFactory.php'),'utf8');
 const addition="        if ($lane === 'W3' && $subscope === 'PUBLIC-CONTENT-53') {\n            $signatureMaterial .= '|'.$executorReleaseSha256.'|'.PublicContent53Package::environment();\n        }\n";
 assert.equal(isPublic53OnlyContextFactoryChange(factory.replace(addition,''),factory),true);
 const legacyFactory=factory.replace('PublicContent53Package::environment()',"(getenv('PUBLIC_CONTENT_53_ENVIRONMENT') ?: 'production')");
 assert.equal(isPublic53OnlyContextFactoryChange(legacyFactory,factory),true);
 assert.equal(isPublic53OnlyContextFactoryChange(legacyFactory,factory.replace('ksort($nested','sort($nested')),false);
 assert.equal(isPublic53OnlyContextFactoryChange(factory.replace(addition,''),factory.replace('ksort($nested','sort($nested')),false);
});
test('publication transport fails closed and recovers malformed phase counts',()=>{
 const env={PUBLIC_CONTENT_53_PUBLISH_ENVIRONMENT:'staging',DEPLOY_PATH:'/srv/app',DEPLOY_USER:'deploy',DEPLOY_HOST:'example.invalid',DEPLOY_PORT:'22',DEPLOY_SHA:'a'.repeat(40),GITHUB_RUN_ID:'123',GITHUB_RUN_ATTEMPT:'1',CONTENT_PROMOTION_AUTOMATION_KEY:'k'.repeat(40)};
 const execution=buildExecution(env,root);assert.ok(execution.args.includes('StrictHostKeyChecking=yes'));
 let recovery=0;
 assert.throws(()=>publish(execution,request=>{if(request.mode==='recover'){recovery++;return {status:0,stdout:JSON.stringify({ok:true,mode:'recover',restored:false,recovery_status:'not_required',source_commit:request.source_commit,workflow_run_id:request.workflow_run_id,workflow_run_attempt:1,package_sha256:request.package_sha256,executor_release_sha256:request.executor_release_sha256,sanitized:true})};}return {status:0,stdout:'{}'};}),error=>error.receipt.recovery_completed === true);
 assert.equal(recovery,1);
});
test('DOM acceptance cannot pass from script-only content or HTTP availability',()=>{
 const row={page_id:'PAGE-02',kind:'page',identity:{locale:'en',slug:'about'},snapshot:{title:'About',content_md:'Expected visible paragraph.'}};
 const html='<html><head><link rel="canonical" href="https://fermatmind.com/en/about"></head><body><main><h1>About</h1><p>Expected visible paragraph.</p></main></body></html>';
 const before={dom:{canonical:['https://fermatmind.com/en/about'],robots:[],alternates:[]}};
 assertDom(row,html,before,'production');
  assertDom(row,html.replace('<main>', '<main><main>').replace('</main>', '</main></main>'),before,'production');
  assert.throws(()=>assertDom(row,html.replace('</main>', '<h1>Duplicate title</h1></main>'),before,'production'),/DOM_STRUCTURE/);
 assert.throws(()=>assertDom(row,html.replace('<p>Expected visible paragraph.</p>','<script>Expected visible paragraph.</script>'),before,'production'),/VISIBLE_BODY/);
 assert.equal(pathFor({kind:'surface',identity:{locale:'zh-CN',slug:'tests_category_career'}}),'/zh/tests/category/career');
});
test('publication retains bounded page diagnostics and separate recovery errors without raw output',()=>{
 const execution={request:{source_commit:'a'.repeat(40),workflow_run_id:'123'},binding:{package_sha256:'b'.repeat(64),executor_release_sha256:'c'.repeat(64)}};
 const failed=()=>({status:1,stdout:JSON.stringify({ok:false,error_code:'public_content_53_revalidation_http_429'})});
 const error=recoveryFailure(execution,failed,new Error('PUBLIC_CONTENT_53_VISIBLE_BODY_PAGE-02_ITEM_14'));
 assert.equal(error.receipt.error_code,'PUBLIC_CONTENT_53_VISIBLE_BODY_PAGE-02_ITEM_14');
 assert.equal(error.receipt.recovery_error_code,'public_content_53_revalidation_http_429');
 assert.equal(error.receipt.recovery_completed,false);
 const unsafe=recoveryFailure(execution,failed,new Error('https://private.invalid/token=secret'));
 assert.equal(unsafe.receipt.error_code,'PUBLIC_CONTENT_53_PUBLICATION_FAILED');
 assert.ok(!JSON.stringify(unsafe.receipt).includes('secret'));
});
