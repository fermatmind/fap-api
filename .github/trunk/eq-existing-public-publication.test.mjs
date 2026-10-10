import assert from 'node:assert/strict';
import test from 'node:test';
import {createHmac} from 'node:crypto';
import {readFileSync, mkdtempSync, mkdirSync, copyFileSync, writeFileSync, rmSync, symlinkSync, linkSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {dirname, join} from 'node:path';
import {inspectPackage, readCandidateRows, workflowSignature, packagePath, executorPaths, isEqExistingOnlyPromotionRegistration, isEqExistingOnlyContextFactoryChange} from './eq-existing-public-package.mjs';
import {buildExecution,publish} from './eq-existing-public-publish.mjs';
import {verifyOnlineCandidates} from './eq-existing-public-online-qa.mjs';
import {expectedVisibleBody} from './eq-new-source-online-qa.mjs';
import {classifyPaths} from './classify-paths.mjs';

const binding=inspectPackage('backend');
const actualConfig=readFileSync('backend/config/content_promotion.php','utf8');
const actualFactory=readFileSync('backend/app/Services/ContentPromotion/PromotionContextFactory.php','utf8');
const rootLine=`        '${packagePath}',\n`;
const cap=", 'EQ-EXISTING-PUBLIC-PAGES' => 'audit_compatible'";
const block="        if ($lane === 'W3' && $subscope === 'EQ-EXISTING-PUBLIC-PAGES') {\n            $signatureMaterial .= '|'.$executorReleaseSha256;\n        }\n";
const previousConfig=actualConfig.replace(rootLine,'').replace(cap,'');
const previousFactory=actualFactory.replace(block,'');
function fixture(run) {
  const root=mkdtempSync(join(tmpdir(),'eq-existing-package-'));
  try {
    const files=new Set([...executorPaths,'config/content_promotion_release_policy.v2.json',`${packagePath}/assets.json`]);
    for(const row of readCandidateRows('backend')) for(const field of ['independent_review_input','independent_review_output','iq_review_input','iq_review_output']) if(row[field]) files.add(row[field].path);
    for(const file of files) { const to=join(root,file); mkdirSync(dirname(to),{recursive:true}); copyFileSync(join('backend',file),to); }
    return run(root);
  } finally { rmSync(root,{recursive:true,force:true}); }
}
test('six reviewed locale rows retain the PHP-tested frozen package digest',()=>{
  assert.equal(binding.package_sha256,'427f656fc3d6ac40eac4819c749e4962a54df0aca1ce7549ccd2eb12e25896ad');
  assert.equal(readCandidateRows('backend').length,6);
});
test('workflow HMAC binds exact executor bytes and six-page scope',()=>{
  const key='fixture-key-'.repeat(5),source='a'.repeat(40),run='12345';
  const material=['content-promotion-v2',source,run,1,'W3','EQ-EXISTING-PUBLIC-PAGES',binding.package_sha256,binding.release_policy_sha256,6,binding.executor_release_sha256].join('|');
  const signature=workflowSignature(binding,key,source,run,1);
  assert.equal(signature,createHmac('sha256',key).update(material).digest('hex'));
  assert.notEqual(signature,workflowSignature({...binding,executor_release_sha256:'b'.repeat(64)},key,source,run,1));
  for(const invalid of [{lane:'W4'},{expected_row_count:5},{subscope:'IQ-PUBLIC-ARTICLES'},{package_sha256:'invalid'}]) assert.throws(()=>workflowSignature({...binding,...invalid},key,source,run,1));
  assert.throws(()=>workflowSignature(binding,key,source,run,2));
});
test('changed or missing independent and IQ review bytes fail before signing',()=>fixture(root=>{
  const row=readCandidateRows(root).find(row=>row.page_id==='SH-02'&&row.identity.locale==='en');
  const path=join(root,row.iq_review_output.path);
  writeFileSync(path,readFileSync(path)+'\nchanged');
  assert.throws(()=>inspectPackage(root),/EQ_EXISTING_PACKAGE_PROOF/);
}));
test('duplicate locale target cannot substitute for another reviewed page',()=>fixture(root=>{
  const path=join(root,packagePath,'assets.json'),data=JSON.parse(readFileSync(path));
  data.candidates[5]=data.candidates[4]; writeFileSync(path,JSON.stringify(data));
  assert.throws(()=>inspectPackage(root),/EQ_EXISTING_PACKAGE_SCOPE/);
}));
test('symlinked and hardlinked proof files cannot become signed package authority',()=>{
  for(const link of [symlinkSync,linkSync]) fixture(root=>{
    const row=readCandidateRows(root)[0],path=join(root,row.independent_review_input.path),other=join(root,'original-proof.json');
    copyFileSync(path,other); rmSync(path); link(other,path);
    assert.throws(()=>inspectPackage(root),/EQ_EXISTING_PACKAGE_PATH/);
  });
});
test('registration admits only the exact public root and W3 capability in their proper arrays',()=>{
  assert.equal(isEqExistingOnlyPromotionRegistration(previousConfig,actualConfig),true);
  assert.equal(isEqExistingOnlyPromotionRegistration(previousConfig,actualConfig+rootLine),false);
  assert.equal(isEqExistingOnlyPromotionRegistration(previousConfig,actualConfig.replace(rootLine,'')+rootLine),false);
  assert.equal(isEqExistingOnlyPromotionRegistration(previousConfig,actualConfig.replace(cap,'').replace("'W4' => [",`'W4' => ['other' => 'audit_compatible'${cap}, `)),false);
  assert.equal(isEqExistingOnlyPromotionRegistration(previousConfig,actualConfig.replace("'content_packs',","'content_packs/private',")),false);
  const former=actualConfig.replace(rootLine,'').replace("        'content_assets/iq_public/entry/20261009-v1',\n",rootLine+"        'content_assets/iq_public/entry/20261009-v1',\n");
  assert.equal(isEqExistingOnlyPromotionRegistration(former,actualConfig),true);
  assert.equal(isEqExistingOnlyPromotionRegistration(former,actualConfig.replace("'content_packs',","'content_packs/private',")),false);
});
test('executor binding addition must precede signature verification and preserves other lanes',()=>{
  assert.equal(isEqExistingOnlyContextFactoryChange(previousFactory,actualFactory),true);
  assert.equal(isEqExistingOnlyContextFactoryChange(previousFactory,actualFactory.replace(block,'')+block),false);
  assert.equal(isEqExistingOnlyContextFactoryChange(previousFactory,actualFactory.replace("strlen($workflowIdentityKey) < 32","strlen($workflowIdentityKey) < 1")),false);
  const relocated=previousFactory.replace('        if (strlen($workflowIdentityKey) < 32\n',block+'        if (strlen($workflowIdentityKey) < 32\n');
  assert.equal(isEqExistingOnlyContextFactoryChange(relocated,actualFactory),true);
  assert.equal(isEqExistingOnlyContextFactoryChange(relocated,actualFactory.replace("'content-promotion-v2'","'changed-private-signature'")),false);
});
const env={EQ_PUBLISH_ENVIRONMENT:'staging',DEPLOY_PATH:'/srv/app',DEPLOY_USER:'deploy',DEPLOY_HOST:'host.invalid',DEPLOY_PORT:'22',DEPLOY_SHA:'a'.repeat(40),GITHUB_RUN_ID:'12345',GITHUB_RUN_ATTEMPT:'1',CONTENT_PROMOTION_AUTOMATION_KEY:'fixture-key-'.repeat(5)};
const response=()=>({ok:true,source_commit:env.DEPLOY_SHA,workflow_run_id:env.GITHUB_RUN_ID,workflow_run_attempt:1,package_sha256:binding.package_sha256,published_count:6,sanitized:true,
  phases:['preflight','draft-import','publish','live-qa'].map(phase=>({phase,receipt_sha256:'b'.repeat(64),readback_count:6}))});
test('transport invokes only the deployed six-page executor and strips unknown remote fields',()=>{
  const execution=buildExecution(env,'backend');
  assert.ok(execution.args.at(-1).includes('run_eq_existing_public_page_publish.php'));
  assert.ok(execution.args.includes('StrictHostKeyChecking=yes'));
  assert.ok(!execution.args.join(' ').includes(env.CONTENT_PROMOTION_AUTOMATION_KEY));
  const result=publish(execution,()=>({status:0,stdout:JSON.stringify({...response(),private_body:'must remain private'})}));
  assert.equal(result.published_count,6);
  assert.ok(!JSON.stringify(result).includes('private_body'));
  assert.throws(()=>buildExecution({...env,DEPLOY_PATH:'/srv/app;command'},'backend'));
});
test('lost or incomplete publication responses recover the same signed execution exactly once',()=>{
  for(const output of ['',JSON.stringify({...response(),published_count:5}),JSON.stringify({...response(),source_commit:'c'.repeat(40)})]) {
    const execution=buildExecution(env,'backend'),calls=[];
    assert.throws(()=>publish(execution,request=>{
      calls.push(request);
      return request.mode==='recover'?{status:0,stdout:JSON.stringify({ok:true,mode:'recover',restored:true,recovery_status:'restored',source_commit:env.DEPLOY_SHA})}:{status:0,stdout:output};
    }),error=>error.receipt.recovery_completed===true);
    assert.deepEqual(calls.map(request=>request.mode),['publish','recover']);
    assert.deepEqual({...calls[1],mode:'publish'},calls[0]);
  }
});
test('unavailable recovery preserves failure and does not expose raw remote errors',()=>{
  assert.throws(()=>publish(buildExecution(env,'backend'),()=>({status:255,stdout:'private secret topology',stderr:'private secret topology'})),error=>error.receipt.recovery_completed===false&&!JSON.stringify(error.receipt).includes('private secret'));
});
test('classifier selects publication only for the exact six-page package or its review proofs',()=>{
  for(const path of [`backend/${packagePath}/assets.json`,'backend/content_assets/eq_public/reviews/20261010-existing-pages/sh02-iq-cross-v4-en/report.md']) assert.equal(classifyPaths([path]).operations.eq_existing_public_pages_publish,true);
  for(const path of ['backend/content_packs/EQ_60/v1/raw/content.json','.github/trunk/eq-existing-public-publish.mjs','backend/app/Services/ContentPromotion/EqExistingPublicPageWriter.php']) assert.equal(classifyPaths([path]).operations.eq_existing_public_pages_publish,false);
  for(const suffix of ['.bak','.unrelated','/child.json']) assert.equal(classifyPaths([`backend/${packagePath}/assets.json${suffix}`]).operations.eq_existing_public_pages_publish,false);
  for(const path of ['backend/content_assets/eq_public/reviews/20261010-existing-pages/unreviewed/report.md','backend/content_assets/eq_public/reviews/20261010-existing-pages/sh02-iq-cross-v4-en/report.md.bak']) assert.equal(classifyPaths([path]).operations.eq_existing_public_pages_publish,false);
});
test('automatic deployment publishes existing pages in both environments and requires business recovery before LKG',()=>{
  const workflow=readFileSync('.github/workflows/deploy.yml','utf8');
  assert.equal(workflow.split('id: eq-existing-publish').length,3);
  assert.equal(workflow.split('run: node .github/trunk/eq-existing-public-publish.mjs').length,3);
  assert.match(workflow,/steps\.eq-existing-publish\.outcome == 'failure'/);
  assert.match(workflow,/eq\.existing_public_pages\.publish\.v1/);
  assert.match(workflow,/eq-existing-public-publication\/production\.json/);
});
function onlineFixtures() {
  const rows=readCandidateRows('backend');
  const registry=row=>{
    const key=row.identity.locale==='en'?'en':'zh';
    const content={[key]:{faq:Array.from({length:11},(_,i)=>({q:`Fixture question ${i}`})),why_choose:{items:[{},{},{},{}]}}};
    for(const operation of row.registry_operations) {
      const parts=operation.path.slice(1).split('/');let cursor=content;
      for(const segment of parts.slice(0,-1)) cursor=cursor[segment]??= {};
      cursor[parts.at(-1)]=operation.value;
    }
    return content;
  };
  const rowFor=url=>rows.find(row=>url.includes(row.identity.slug)&&(url.includes('locale='+row.identity.locale)||url.includes('/'+(row.identity.locale==='en'?'en':'zh')+'/')));
  return {
    fetcher:async url=>{
      if(url.includes('/api/')&&url.includes('/career/guides/')) throw new Error('Guide API must use the real career-guides route');
      const row=rowFor(url),entry=row.page_id==='EQ-01',article=row.page_id==='EQ-02';
      const payload=entry?{ok:true,primary_slug:row.identity.slug,scale_code:'EQ_60',is_public:true,content_i18n_json:registry(row)}:
        {ok:true,[article?'article':'guide']:{...row.identity,...row.snapshot,...(!article?{body_md:row.snapshot.content_md}:{})},seo_surface_v1:{title:row.snapshot.seo_title,description:row.snapshot.seo_description}};
      const json=url.includes('/api/');
      return {status:200,headers:new Headers({'content-type':json?'application/json':'text/html'}),text:async()=>json?JSON.stringify(payload):'<html>Transport only</html>'};
    },
    renderer:async url=>{
      const row=rowFor(url),entry=row.page_id==='EQ-01';
      const body=entry?row.registry_operations.filter(operation=>/\/(?:intro|body|a)$/.test(operation.path)).map(operation=>expectedVisibleBody(operation.value)).join(' ')+Array.from({length:11},(_,i)=>`Fixture question ${i}`).join(' '):expectedVisibleBody(row.snapshot.content_md);
      return {title:row.snapshot.seo_title+' | FermatMind',description:row.snapshot.seo_description,headings:[row.snapshot.title],articleVisible:true,body};
    }
  };
}
test('online acceptance covers all six native identities in desktop and 390px layouts',async()=>{
  const {fetcher,renderer}=onlineFixtures(),viewports=[];
  const result=await verifyOnlineCandidates('staging','backend',fetcher,(url,options)=>{viewports.push(options.width);return renderer(url);});
  assert.deepEqual(result,{environment:'staging',api_readback_count:6,seo_readback_count:4,ssr_readback_count:6,rendered_viewport_count:12});
  assert.deepEqual(viewports,Array.from({length:6},()=>[390,1366]).flat());
});
test('stale EQ entry leaf and hidden native Guide body both fail online acceptance',async()=>{
  const {fetcher,renderer}=onlineFixtures();
  await assert.rejects(verifyOnlineCandidates('staging','backend',async url=>{
    const response=await fetcher(url);
    if(!url.includes('/scales/lookup')) return response;
    const payload=JSON.parse(await response.text());payload.content_i18n_json.zh.why_choose.intro='Old copy';
    return {...response,text:async()=>JSON.stringify(payload)};
  },renderer),/EQ_EXISTING_ONLINE_REGISTRY_BODY/);
  await assert.rejects(verifyOnlineCandidates('staging','backend',fetcher,async url=>({...await renderer(url),...(url.includes('/career/guides/')?{articleVisible:false}:{})})),/EQ_SSR_BODY_MISMATCH/);
});
