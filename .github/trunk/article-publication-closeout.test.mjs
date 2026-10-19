import assert from 'node:assert/strict';
import {readFileSync,mkdtempSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {spawnSync} from 'node:child_process';
import {resolve} from 'node:path';
import {readback} from './mbti-report-article-readback.mjs';
import test from 'node:test';
import {classifyPaths} from './classify-paths.mjs';
import {requiredSeconds} from './production-release-budget.mjs';

const read = path => readFileSync(new URL(path, import.meta.url), 'utf8');

test('recovery and admitted deploy serialize production mutations together', () => {
  const deploy = read('../workflows/deploy.yml');
  const recovery = read('../workflows/recovery.yml');
  assert.match(deploy, /format\('trunk-deploy-\{0\}', github.repository\)/);
  assert.match(recovery, /group: trunk-deploy-\$\{\{ github.repository \}\}/);
  const recipe = read('../../deploy.php');
  assert.match(recipe, /actions\/runs\/'.\$owner/);
  assert.match(recipe, /!== 'completed'/);
});

test('normal and failed-smoke restoration budgets leave time inside the job', () => {
  const deploy = read('../workflows/deploy.yml');
  assert.equal((deploy.match(/timeout --signal=TERM --kill-after=30s 30m php \/tmp\/dep.phar deploy:code-only/g) ?? []).length, 2);
  assert.equal(requiredSeconds(60,{}),6300);
  assert.equal(requiredSeconds(60,{eq_new_source_articles_publish:true}),8900);
  assert.ok(requiredSeconds(60,{public_content_53_publish:true})>7200);
  assert.ok(requiredSeconds(30,{eq_new_source_articles_publish:true,iq_eq_topic_publish:true})>
    requiredSeconds(30,{eq_new_source_articles_publish:true}));
});

test('article cache closeout is selected only for its existing content and readers', () => {
  assert.equal(classifyPaths(['backend/app/Services/Cms/MbtiReportArticleRelease.php']).operations.mbti_report_articles_closeout,true);
  assert.equal(classifyPaths(['backend/content_assets/mbti_report_articles/20261019/en.md']).operations.mbti_report_articles_closeout,true);
  assert.equal(classifyPaths(['backend/app/Services/Ops/SchedulerHeartbeatService.php']).operations.mbti_report_articles_closeout,false);
  assert.equal(classifyPaths(['backend/tests/Feature/MbtiReportArticleReleaseTest.php']).operations.mbti_report_articles_closeout,false);
  const recipe = read('../../deploy.php');
  assert.match(recipe, /after\('cache:accept-public-projection', 'mbti:closeout-reviewed-articles'\)/);
  assert.match(recipe, /after\('deploy:success', 'cache:accept-public-projection'\)/);
});


test('runner readback rejects the old LKG FAQ projection without invoking old CLI', async () => {
  const root=resolve('backend/content_assets/mbti_report_articles/20261019');
  const pkg=JSON.parse(readFileSync(resolve(root,'package.json'),'utf8'));
  const fake=(truncate)=>async url=>{
    if(url.endsWith('/revision')) return {status:200,json:async()=>({revision:'a'.repeat(40)})};
    const row=pkg.articles.find(r=>url.includes(r.identity.slug));
    const seo=url.includes('/seo?');
    return {status:200,json:async()=>seo?{meta:{title:row.seo_title,description:row.seo_description}}:{
      article:{...row.identity,title:row.title,excerpt:row.excerpt,content_md:readFileSync(resolve(root,row.file),'utf8')},
      answer_surface_v1:{faq_blocks:truncate?row.faq_items.slice(0,6):row.faq_items},
    }};
  };
  await assert.rejects(readback('a'.repeat(40),fake(true),root),/MBTI_READBACK_FAQ/);
  assert.deepEqual((await readback('a'.repeat(40),fake(false),root)).faq_counts,[6,8]);
  const recipe=read('../../deploy.php');
  const closeout=recipe.slice(recipe.indexOf("task('mbti:closeout-reviewed-articles'"));
  assert.match(closeout,/get\('deploy_mode'\) === 'code_only'/);
  const rollback=recipe.slice(recipe.indexOf("task('rollback:healthcheck'"),recipe.indexOf("task('rollback:healthcheck'")+800);
  assert.doesNotMatch(rollback,/mbti:closeout-reviewed-articles/);
});


test('both restoration receipts keep code recovery separate from a failed article contract', () => {
  const workflow=read('../workflows/deploy.yml');
  for(const marker of ['            rollback_command_succeeded=false','          rollback_ok=false']) {
    const start=workflow.indexOf(marker);
    assert.notEqual(start,-1);
    const end=workflow.indexOf('> lkg-rollback-receipt.json',start)+'> lkg-rollback-receipt.json'.length;
    const script=workflow.slice(start,end);
    const directory=mkdtempSync(resolve(tmpdir(),'article-restore-receipt-'));
    try {
      const sha='a'.repeat(40);
      const result=spawnSync('bash',['-eu','-c',script],{cwd:directory,env:{...process.env,
        rollback_rc:'0',safe_restored:sha,restored:sha,lkg_sha:sha,candidate_sha:'b'.repeat(40),
        GITHUB_RUN_ID:'123',GITHUB_RUN_ATTEMPT:'1',content_contract:JSON.stringify({ok:false,reason:'MBTI_READBACK_FAQ'}),
      },encoding:'utf8'});
      assert.equal(result.status,0,result.stderr);
      const receipt=JSON.parse(readFileSync(resolve(directory,'lkg-rollback-receipt.json'),'utf8'));
      assert.equal(receipt.status,'restored_content_mismatch');
      assert.equal(receipt.rollback.restoration_completed,true);
      assert.equal(receipt.rollback.healthchecks_succeeded,false);
      assert.equal(receipt.incident.content_contract_mismatch,true);
    } finally {rmSync(directory,{recursive:true,force:true});}
  }
});
