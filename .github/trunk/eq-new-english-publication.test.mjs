import assert from 'node:assert/strict';
import test from 'node:test';
import { cpSync, mkdtempSync, mkdirSync, readFileSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { dirname, join } from 'node:path';
import { executorPaths as sourcePaths, inspectPackage as sourcePackage } from './eq-new-source-package.mjs';
import { inspectPackage, executorPaths } from './eq-new-english-package.mjs';
import { buildExecution, publish } from './eq-new-english-publish.mjs';
import { verifyOnlineCandidates, expectedVisibleBody } from './eq-new-source-online-qa.mjs';
import { classifyPaths } from './classify-paths.mjs';
import { selectOperations } from './impact-consumers.mjs';

const env = { EQ_PUBLISH_ENVIRONMENT:'staging', DEPLOY_PATH:'/srv/application', DEPLOY_USER:'deploy', DEPLOY_HOST:'host.invalid', DEPLOY_PORT:'22', DEPLOY_SHA:'a'.repeat(40), GITHUB_RUN_ID:'1234', GITHUB_RUN_ATTEMPT:'1', CONTENT_PROMOTION_AUTOMATION_KEY:'test-key-'.repeat(8) };
function fixture() {
  const root = mkdtempSync(join(tmpdir(),'eq-english-publication-'));
  for (const path of new Set([...sourcePaths,...executorPaths,'config/content_promotion_release_policy.v2.json'])) {
    mkdirSync(dirname(join(root,path)),{recursive:true});
    cpSync(join('backend',path),join(root,path));
  }
  cpSync('backend/content_assets/eq_public',join(root,'content_assets/eq_public'),{recursive:true});
  const marker = join(root,'content_assets/eq_public/candidate/20261009-new-articles/en-publication.json');
  writeFileSync(marker,JSON.stringify({schema:'fermatmind.eq_english_article_publication.v1',source_commit:'b'.repeat(40),staging_source_commit:'c'.repeat(40),source_package_sha256:sourcePackage(root).package_sha256,locale:'en',expected_row_count:3})+'\n');
  return {root,marker};
}
test('English uses its own package digest and existing W3 Article signature scope', () => {
  const {root,marker}=fixture();
  try {
    const binding=inspectPackage(root);
    assert.notEqual(binding.package_sha256,sourcePackage(root).package_sha256);
    assert.equal(binding.subscope,'W3-ARTICLES');
    const execution=buildExecution(env,root);
    assert.ok(execution.args.at(-1).includes('run_eq_new_english_article_publish.php'));
    assert.ok(!execution.args.join(' ').includes(env.CONTENT_PROMOTION_AUTOMATION_KEY));
    const value=JSON.parse(readFileSync(marker));value.source_package_sha256='f'.repeat(64);writeFileSync(marker,JSON.stringify(value));
    assert.throws(()=>inspectPackage(root),/MARKER_INVALID/);
  } finally {rmSync(root,{recursive:true,force:true});}
});
test('English phase failure has one bounded exact recovery and no remote body disclosure',()=>{
  const {root}=fixture();
  try {
    const execution=buildExecution(env,root),calls=[];
    assert.throws(()=>publish(execution,request=>{
      calls.push(request.mode);
      return request.mode==='recover'
        ? {status:0,stdout:JSON.stringify({ok:true,mode:'recover',recovery_status:'not_required',restored:false,source_commit:env.DEPLOY_SHA})}
        : {status:1,stdout:JSON.stringify({ok:false,error_code:'workflow_identity_signature_invalid',body:'private original'})};
    }),error=>error.receipt.schema==='eq.new_english_articles.publish.v1' && error.receipt.recovery_completed===true
      && error.receipt.error_code==='workflow_identity_signature_invalid' && !JSON.stringify(error.receipt).includes('private'));
    assert.deepEqual(calls,['publish','recover']);
  } finally {rmSync(root,{recursive:true,force:true});}
});
test('English publication selection never republishes Chinese or private result content',()=>{
  const result=classifyPaths(['backend/content_assets/eq_public/candidate/20261009-new-articles/en-publication.json']).operations;
  assert.equal(result.eq_new_english_articles_publish,true);
  assert.equal(result.eq_new_source_articles_publish,false);
  const actual = selectOperations(['backend/content_assets/eq_public/candidate/20261009-new-articles/en-publication.json']);
  for(const family of ['big5','riasec','enneagram','eq60']) assert.equal(actual[family+'_private_publish'],false);
  assert.equal(classifyPaths(['backend/app/Services/ContentPromotion/EqEnglishArticlePackage.php']).operations.eq_new_english_articles_publish,false);
});
test('both existing deploy environments consume raw classification and require English business recovery before LKG',()=>{
  const workflow=readFileSync('.github/workflows/deploy.yml','utf8');
  assert.equal((workflow.match(/id: eq-english-publish/g)??[]).length,2);
  assert.equal((workflow.match(/fromJSON\(needs\.policy\.outputs\.classification\)\.operations\.eq_new_english_articles_publish/g)??[]).length,2);
  assert.doesNotMatch(workflow,/fromJSON\(needs\.policy\.outputs\.operations\)/);
  const rollback=workflow.split('      - name: Restore exact LKG after Career publisher failure')[1];
  assert.match(rollback,/steps\.eq-english-publish\.outcome == 'failure'/);
  assert.ok(rollback.indexOf('eq.new_english_articles.publish.v1')<rollback.indexOf('export DEPLOY_SHA="$lkg_sha"'));
  assert.match(rollback,/eq-new-english-publication\/production\.json/);
});
test('English API SEO and rendered body acceptance use exactly the three English routes',async()=>{
  const rows=JSON.parse(readFileSync('backend/content_assets/eq_public/candidate/20261009-new-articles/assets.json')).candidates.filter(row=>row.identity.locale==='en');
  const seen=[];
  const escape=s=>s.replaceAll('&','&amp;').replaceAll('<','&lt;').replaceAll('>','&gt;').replaceAll('"','&quot;');
  const fetcher=async address=>{
    const url=new URL(address);seen.push(url.pathname);
    if(url.hostname==='staging.fermatmind.com') return new Response('<html></html>',{headers:{'content-type':'text/html'}});
    assert.equal(url.searchParams.get('locale'),'en');
    const parts=url.pathname.split('/'),slug=parts.at(-1)==='seo'?parts.at(-2):parts.at(-1);
    const row=rows.find(row=>row.identity.slug===slug);assert.ok(row);
    return new Response(JSON.stringify({ok:true,article:{...row.snapshot,slug,locale:'en'},seo_surface_v1:{title:row.snapshot.seo_title,description:row.snapshot.seo_description}}),{headers:{'content-type':'application/json'}});
  };
  const renderer=address=>{
    const row=rows.find(row=>address.endsWith(row.identity.slug));assert.ok(row);assert.ok(address.includes('/en/articles/'));
    const body=row.snapshot.content_md.replace(/\[([^\]]+)\]\([^)]+\)/g,'$1').replace(/[*`_]/g,'');
    const title=row.snapshot.seo_title.replace(/(?:\s*\|\s*FermatMind)+$/i,'').trim()+' | FermatMind';
    return {title,description:row.snapshot.seo_description,headings:[row.snapshot.title],articleVisible:true,body:expectedVisibleBody(row.snapshot.content_md)};
  };
  const receipt=await verifyOnlineCandidates('staging','backend',fetcher,renderer,'en');
  assert.equal(receipt.api_readback_count,3);assert.equal(seen.length,9);
  assert.ok(seen.every(path=>!path.startsWith('/zh/')));
});
