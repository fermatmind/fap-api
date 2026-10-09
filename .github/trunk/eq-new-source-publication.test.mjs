import assert from 'node:assert/strict';
import test from 'node:test';
import { createHmac } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { spawnSync } from 'node:child_process';
import { inspectPackage, workflowSignature, isEqOnlyPromotionRegistration } from './eq-new-source-package.mjs';
import { buildExecution, publish } from './eq-new-source-publish.mjs';
import { classifyPaths } from './classify-paths.mjs';

const binding = inspectPackage('backend');
const env = { EQ_PUBLISH_ENVIRONMENT: 'staging', DEPLOY_PATH: '/srv/application', DEPLOY_USER: 'deploy', DEPLOY_HOST: 'host.invalid', DEPLOY_PORT: '22', DEPLOY_SHA: 'a'.repeat(40), GITHUB_RUN_ID: '1234', GITHUB_RUN_ATTEMPT: '1', CONTENT_PROMOTION_AUTOMATION_KEY: 'test-key-'.repeat(8) };
const phases = ['preflight','draft-import','publish','live-qa'].map(phase => ({ phase, receipt_sha256: 'b'.repeat(64), readback_count: 3 }));
const response = () => ({ schema: 'eq.new_source_articles.publish.v1', ok: true, source_commit: env.DEPLOY_SHA, workflow_run_id: env.GITHUB_RUN_ID, workflow_run_attempt: 1, package_sha256: binding.package_sha256, published_count: 3, phases, sanitized: true });

test('workflow identity uses existing HMAC material and refuses reused attempts', () => {
  const actual = workflowSignature(binding, env.CONTENT_PROMOTION_AUTOMATION_KEY, env.DEPLOY_SHA, env.GITHUB_RUN_ID, 1);
  const material = ['content-promotion-v2', env.DEPLOY_SHA, env.GITHUB_RUN_ID, 1, 'W3', 'EQ-NEW-SOURCE-ARTICLES', binding.package_sha256, binding.release_policy_sha256, 3].join('|');
  assert.equal(actual, createHmac('sha256',env.CONTENT_PROMOTION_AUTOMATION_KEY).update(material).digest('hex'));
  assert.throws(() => workflowSignature(binding, env.CONTENT_PROMOTION_AUTOMATION_KEY, env.DEPLOY_SHA, env.GITHUB_RUN_ID, 2));
  assert.throws(() => buildExecution({...env, DEPLOY_PATH: '/srv/unsafe;command'},'backend'));
});
test('publication is selected only for exact package or original independent proof changes', () => {
  for (const path of ['backend/content_assets/eq_public/candidate/20261009-new-articles/assets.json', 'backend/content_assets/eq_public/reviews/20261009-new-articles/eq-new-pages-v5-en-v1/report.md']) assert.equal(classifyPaths([path]).operations.eq_new_source_articles_publish, true);
  for (const path of ['backend/app/Services/ContentPromotion/Adapters/EqNewSourceArticlePromotionAdapter.php', '.github/trunk/eq-new-source-publish.mjs', 'backend/content_packs/EQ_60/v1/pack.json','backend/content_assets/eq_public/candidate/20261009-new-articles/README.md']) assert.equal(classifyPaths([path]).operations.eq_new_source_articles_publish, false);
});
test('transport binds exact scope and whitelists metadata without forwarding body or secrets', () => {
  const execution = buildExecution(env,'backend');
  assert.ok(execution.args.includes('StrictHostKeyChecking=yes'));
  assert.ok(!execution.args.join(' ').includes(env.CONTENT_PROMOTION_AUTOMATION_KEY));
  const result = publish(execution, () => ({ status: 0, stdout: JSON.stringify({...response(), private_body:'must not escape'}) }));
  assert.equal(result.published_count, 3);
  assert.ok(!JSON.stringify(result).includes('must not escape'));
});
test('malformed, incomplete or lost postpublication responses trigger exact recovery once', () => {
  for (const output of ['', JSON.stringify({...response(), published_count: 2}), JSON.stringify({...response(), source_commit:'c'.repeat(40)})]) {
    const calls=[];
    const execution=buildExecution(env,'backend');
    assert.throws(() => publish(execution, request => {
      calls.push(request.mode);
      return request.mode==='recover' ? {status:0,stdout:JSON.stringify({ok:true,mode:'recover',restored:true,recovery_status:'restored',source_commit:env.DEPLOY_SHA})} : {status:0,stdout:output};
    }), error => error.receipt.recovery_completed === true);
    assert.deepEqual(calls,['publish','recover']);
  }
});
test('unavailable recovery remains a failed delivery', () => {
  assert.throws(() => publish(buildExecution(env,'backend'), () => ({status:255, stdout:'',stderr:'secret topology'})), error => error.receipt.recovery_completed===false && !JSON.stringify(error.receipt).includes('secret'));
});
test('permanent workflow serializes source publishing and includes automatic LKG recovery', () => {
  const workflow=readFileSync('.github/workflows/deploy.yml','utf8');
  const staging=workflow.split('  staging:')[1].split('  production:')[0];
  const production=workflow.split('  production:')[1];
  assert.ok(staging.indexOf('id: eq-source-publish')<staging.indexOf('Record staging timing'));
  assert.ok(production.indexOf('id: eq-source-publish')<production.indexOf('Restore exact LKG after Career publisher failure'));
  assert.match(production,/steps\.career-publish\.outcome == 'failure' \|\| steps\.eq-source-publish\.outcome == 'failure'/);
  assert.match(production,/steps\.baseline\.outputs\.skip != 'true' && fromJSON\(needs\.policy\.outputs\.operations\)\.eq_new_source_articles_publish == true/);
});

test('exact EQ registration excludes only that delta; policy and private adapter changes remain conservative', () => {
  const after=readFileSync('backend/config/content_promotion.php','utf8');
  const before=after.replace("        'content_assets/eq_public/candidate/20261009-new-articles',\n",'')
    .replace(", 'EQ-NEW-SOURCE-ARTICLES' => 'audit_compatible'",'');
  assert.equal(isEqOnlyPromotionRegistration(before,after),true);
  assert.equal(isEqOnlyPromotionRegistration(before,after.replace("'eq' => 'audit_compatible'","'eq' => 'fail_closed_legacy_audit'")),false);
  assert.equal(isEqOnlyPromotionRegistration(before,after+'\n'),false);
});

test('actual LKG admission predicate refuses an unavailable or unbound business recovery',()=>{
 const workflow=readFileSync('.github/workflows/deploy.yml','utf8');
 const block=workflow.split('      - name: Restore exact LKG after Career publisher failure')[1];
 const predicate=block.split('jq -e --arg sha "$DEPLOY_SHA" --arg run "$GITHUB_RUN_ID" \'')[1].split("' \"$RUNNER_TEMP/eq-new-source-publication/production.json\"")[0];
 assert.ok(block.indexOf('recovery_completed == true')<block.indexOf('export DEPLOY_SHA="$lkg_sha"'));
 const receipt={schema:'eq.new_source_articles.publish.v1',ok:false,source_commit:'a'.repeat(40),workflow_run_id:'1234',workflow_run_attempt:1,sanitized:true,recovery_completed:true};
 const accepted=value=>spawnSync('jq',['-e','--arg','sha','a'.repeat(40),'--arg','run','1234',predicate],{input:JSON.stringify(value),encoding:'utf8'}).status===0;
 assert.equal(accepted(receipt),true);
 for(const changed of [{recovery_completed:false},{source_commit:'b'.repeat(40)},{workflow_run_id:'999'},{workflow_run_attempt:2}]) assert.equal(accepted({...receipt,...changed}),false);
});
