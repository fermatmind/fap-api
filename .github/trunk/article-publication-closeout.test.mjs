import assert from 'node:assert/strict';
import {readFileSync,mkdtempSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {spawnSync,execFileSync} from 'node:child_process';
import {resolve} from 'node:path';
import {readback} from './mbti-report-article-readback.mjs';
import test from 'node:test';
import {classifyPaths} from './classify-paths.mjs';
import {requiredSeconds} from './production-release-budget.mjs';
import {classifyRelease} from './classify-release.mjs';
import {selectOperations} from './impact-consumers.mjs';

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
  assert.equal(requiredSeconds(60,{}),6360);
  assert.equal(requiredSeconds(60,{eq_new_source_articles_publish:true}),8960);
  assert.ok(requiredSeconds(60,{public_content_53_publish:true})>7200);
  assert.ok(requiredSeconds(30,{eq_new_source_articles_publish:true,iq_eq_topic_publish:true})>
    requiredSeconds(30,{eq_new_source_articles_publish:true}));
});

test('pending IQ publication fits the production job with matching remaining budget', () => {
  const workflow = read('../workflows/deploy.yml');
  const production = workflow.match(/\n  production:\n([\s\S]*?)(?=\n  [\w-]+:|$)/)?.[1];
  assert.ok(production);
  const jobSeconds = Number(production.match(/^    timeout-minutes: (\d+)$/m)?.[1]) * 60;
  const remainingSeconds = Number(production.match(/remaining=\$\(\((\d+) - \$\(date/)?.[1]);
  assert.equal(remainingSeconds, jobSeconds);
  const operations = classifyPaths(['backend/content_assets/iq_public/entry/20261009-v1/IQ-01-en.md']).operations;
  assert.equal(operations.iq_public_scale_publish, true);
  const required = requiredSeconds(30, operations);
  assert.equal(required, 7560);
  assert.ok(jobSeconds - 60 >= required, 'reserve a minute of setup before admitting the pending IQ publication');
  const admission = production.match(/          remaining=\$\(\([\s\S]*?          test "\$remaining" -ge "\$required"/)?.[0];
  assert.ok(admission);
  const script = admission.replace(/'\$\{\{ needs.policy.outputs.operations \}\}'/, `'${Buffer.from(JSON.stringify(operations)).toString('base64')}'`);
  const runAdmission = elapsed => spawnSync('bash', ['-eu', '-c', script], {encoding:'utf8', env:{...process.env,
    deploy_minutes:'30', PRODUCTION_JOB_STARTED_EPOCH:String(Math.floor(Date.now()/1000)-elapsed),
  }});
  const admitted = runAdmission(60);
  assert.equal(admitted.status, 0, admitted.stderr);
  assert.notEqual(runAdmission(jobSeconds-required+60).status, 0, 'actual workflow must still refuse admission when recovery no longer fits');
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


test('the real pending Career release retains its consumers and fits with recovery and kill grace', () => {
  const production = '444e525c80d171f4a5070509ed0defb439646834';
  const candidate = '21f43566fccc0f06578c37147b69c1c20d17eb20';
  const pending = execFileSync('git', ['diff', '--name-only', production, candidate], {encoding:'utf8'}).trim().split('\n');
  const patch = ['.github/workflows/deploy.yml', '.github/trunk/production-release-budget.mjs',
    '.github/trunk/article-publication-closeout.test.mjs', '.github/trunk/career-publisher-workflow-contract.test.mjs'];
  const release = classifyRelease({pushBase:candidate,head:'a'.repeat(40),baseline:{sha:production,runId:39368597477},
    isAncestor:()=>true,diffPaths:base=>base===candidate?patch:pending});
  Object.assign(release.operations, selectOperations(release.paths));
  assert.equal(release.scope.validation_base_sha,production);
  for (const name of ['publisher_required','career_current_authority_release','career_cache','url_truth','big5_tests','mbti_modes']) {
    assert.equal(release.operations[name],true,name);
  }
  for (const name of ['big5_private_publish','riasec_private_publish','enneagram_private_publish','eq60_private_publish']) {
    assert.equal(release.operations[name],false,name);
  }
  const required=requiredSeconds(60,release.operations);
  assert.equal(required,3600+30+1800+30+900);
  assert.ok(7200-50 >= required);
  assert.ok(7200-841 < required);
  assert.ok(requiredSeconds(60,{...release.operations,eq_new_source_articles_publish:true})>7200);
  assert.throws(()=>requiredSeconds(30,release.operations),/CAREER_FORWARD_BUDGET_INVALID/);
  assert.throws(()=>requiredSeconds(60,{career_current_authority_release:'true'}),/OPERATION_INVALID/);
  assert.throws(()=>requiredSeconds(60,null),/OPERATIONS_INVALID/);
});
