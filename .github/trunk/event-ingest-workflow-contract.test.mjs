import assert from 'node:assert/strict';
import { readFileSync, readdirSync } from 'node:fs';
import test from 'node:test';
import { classifyPaths } from './classify-paths.mjs';

const deploy = readFileSync('deploy.php', 'utf8');
const workflow = readFileSync('.github/workflows/deploy.yml', 'utf8');
const helper = readFileSync('backend/scripts/deploy/event_ingest_runtime.php', 'utf8');

test('EVENT delivery uses only normal environment-scoped activation steps', () => {
  assert.equal((workflow.match(/EVENT_INGEST_TOKEN: \$\{\{ vars\.EVENT_INGEST_ENABLED == '1' && secrets\.EVENT_INGEST_TOKEN \|\| '' \}\}/g) || []).length, 3);
  assert.equal((workflow.match(/EVENT_INGEST_ENABLED: \$\{\{ vars\.EVENT_INGEST_ENABLED \}\}/g) || []).length, 3);
  for (const name of ['Materialize inactive staging measurement candidate', 'Deploy staging and run repository smoke chain', 'Deploy once and automatically restore LKG after committed smoke failure']) {
    const block = workflow.slice(workflow.indexOf(`      - name: ${name}`)).split('\n      - name: ')[0];
    assert.match(block, /EVENT_INGEST_TOKEN:/);
    assert.match(block, /php \/tmp\/dep\.phar/);
  }
  assert.deepEqual(readdirSync('.github/workflows').filter(n => n.endsWith('.yml')).sort(), ['ci.yml', 'deploy.yml', 'nightly.yml', 'recovery.yml']);
});

test('EVENT helper is an infrastructure runtime change, without extra domain authority', () => {
  const result = classifyPaths(['backend/scripts/deploy/event_ingest_runtime.php']);
  assert.equal(result.flags.infrastructure_deployment, true);
  assert.equal(result.deploy, true);
  assert.equal(result.flags.content_assets, false);
  assert.equal(result.flags.seo_discoverability, false);
  assert.equal(result.flags.backward_compatible_migration, false);
});

test('private input stays out of artifacts, arguments and shared environment', () => {
  assert.match(deploy, /chmod\(\$local, 0600\)/);
  assert.match(deploy, /putenv\('EVENT_INGEST_TOKEN'\)/);
  assert.match(deploy, /upload\(\$local, \$remote\)/);
  assert.match(deploy, /unlink\(\$local\)/);
  assert.match(helper, /CURLOPT_POSTFIELDS => '\{\}'/);
  assert.match(helper, /X-Track-Ingest-Token:/);
  assert.match(helper, /CURLOPT_FOLLOWLOCATION => false/);
  assert.match(helper, /CURLOPT_RESOLVE => \[\$host\.':443:127\.0\.0\.1'\]/);
  assert.match(helper, /EVENT_RUNTIME_FAILED/);
  assert.match(helper, /if \(! function_exists\('curl_init'\)\)/);
  assert.doesNotMatch(helper, /\$error->getMessage|file_put_contents\([^\n]*\.env/);
  assert.doesNotMatch(helper, /CURLOPT_VERBOSE|SSL_VERIFYPEER => false/);
  for (const artifact of workflow.matchAll(/path: \|\n((?:            .*\n)+)/g)) {
    assert.doesNotMatch(artifact[1], /incoming\.json|input\.json|lkg\.json|\.event-ingest|config\.php|backend\/$/);
  }
  assert.match(workflow, /event-ingest-runtime-staging-\*\.json/);
  assert.match(workflow, /event-ingest-runtime-production-\*\.json/);
});

test('managed cache verifies before activation and has process/FPM runtime readback', () => {
  const block = deploy.slice(deploy.indexOf("task('artisan:config:cache'")).split("\ntask('")[0];
  assert.match(block, /deployEventRuntime\('install'/);
  assert.match(block, /deployEventRuntime\('compile'/);
  assert.match(block, /deployEventRuntime\('verify'/);
  assert.match(deploy, /before\('healthcheck:staging-big-five-report-delivery', 'event-ingest:runtime-receipt'\)/);
  assert.match(deploy, /after\('healthcheck:queue-smoke', 'healthcheck:staging-big-five-report-delivery'\)/);
  assert.match(helper, /rename\(\$candidate, \$backend\.'\/bootstrap\/cache\/config\.php'\)/);
  assert.match(helper, /EVENT_CONFIG_MISMATCH/);
  assert.match(helper, /EVENT_MANAGEMENT_REQUIRED/);
  assert.match(deploy, /before\('deploy:symlink', 'event-ingest:candidate-verify'\)/);
  assert.match(helper, /\$input\['enabled'\] \? 422 : 503/);
});

test('both existing bounded LKG branches use frozen prior input, not new runner token', () => {
  assert.equal((workflow.match(/export EVENT_INGEST_RUNTIME_ROLLBACK_SOURCE="\$event_lkg"/g) || []).length, 2);
  assert.equal((workflow.match(/event_lkg="\$DEPLOY_PATH\/releases\/trunk-\$\{candidate_sha:0:12\}-\$\{GITHUB_RUN_ID\}\/backend\/\.event-ingest\/lkg\.json"/g) || []).length, 2);
  assert.match(deploy, /EVENT_LKG_SOURCE_REQUIRED/);
  assert.match(helper, /self::input\(self::read\(\$rollback\), \$revision, \$environment\)/);
  assert.match(deploy, /\$rollback === '' && \$intent === '1'/);
  const rebuild = deploy.slice(deploy.indexOf("task('bootstrap-cache:rebuild-current'")).split("\ntask('")[0];
  assert.match(rebuild, /if \(! \$managed\)/);
  assert.doesNotMatch(rebuild, /getCachedConfigPath\(\)/);
  assert.match(deploy, /'rollback:healthcheck:event-ingest'/);
});

// Execute the actual Deployer expectation and receipt functions with a fixed
// public receipt. No HTTP, deploy, private input or application cache is used.
import { mkdtempSync, writeFileSync, existsSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join } from 'node:path';
import { spawnSync } from 'node:child_process';
const receiptFunctions = deploy.slice(deploy.indexOf('function deployEventManaged('), deploy.indexOf("\ntask('bootstrap-cache:clear-release'"));
const verified = {schema_version:'fermatmind.event-ingest-runtime.v1',revision:'b'.repeat(40),environment:'production',enabled:true,config_cached:true,release_bound:true,empty_envelope_status:422,status:'verified'};
const unmanaged = {schema_version:verified.schema_version,revision:verified.revision,environment:verified.environment,status:'unmanaged'};
const receiptCases = [
  ['true legacy', '', false, false, unmanaged, true],
  ['true legacy standalone rollback', '', false, false, unmanaged, true, true],
  ['explicit enable cannot become unmanaged', '1', false, false, unmanaged, false],
  ['explicit disable cannot become unmanaged', '0', false, false, unmanaged, false],
  ['managed inheritance cannot become unmanaged', '', true, false, unmanaged, false],
  ['rollback cannot become unmanaged', '', false, true, unmanaged, false],
  ['enabled verified', '1', true, false, verified, true],
  ['inherited verified', '', true, false, verified, true],
  ['disabled verified', '0', true, false, {...verified,enabled:false,empty_envelope_status:503}, true],
  ['unexpected enabled state', '0', true, false, verified, false],
  ['unexpected disabled state', '1', true, false, {...verified,enabled:false,empty_envelope_status:503}, false],
  ['uncached', '1', true, false, {...verified,config_cached:false}, false],
  ['string cached', '1', true, false, {...verified,config_cached:'true'}, false],
  ['unbound', '1', true, false, {...verified,release_bound:false}, false],
  ['wrong HTTP', '1', true, false, {...verified,empty_envelope_status:200}, false],
  ['wrong status', '1', true, false, {...verified,status:'running'}, false],
  ['verified without management expectation', '', false, false, verified, false],
];
for (const [name,intent,managed,rollback,receipt,accepted,boundRevision=false] of receiptCases) {
  test(`EVENT F3 actual receipt ${name}`, () => {
    const root=mkdtempSync(join(tmpdir(),'event-receipt-fixture-'));
    const script=join(root,'fixture.php');
    const setup=`<?php
      $GLOBALS['managed']=${managed?'true':'false'};$GLOBALS['receipt']=json_decode($argv[1],true);
      putenv('EVENT_INGEST_ENABLED=${intent}');putenv('EVENT_INGEST_RUNTIME_ROLLBACK_SOURCE=${rollback?'/fixture/lkg.json':''}');
      putenv('DEPLOY_SHA=${verified.revision}');putenv('GITHUB_REPOSITORY=fermatmind/fap-api');putenv('GITHUB_RUN_ID=123');putenv('GITHUB_RUN_ATTEMPT=1');
      function test($command){return $GLOBALS['managed'];}
      function deployPlaceholderPathArg($root,$relative=''){return escapeshellarg($root.'/'.$relative);}
      function currentHost(){return new class {function getAlias(){return 'production';}};}
      function deployEventRuntime($command,$root,$extra=[],$revision=null){$GLOBALS['probe_arguments']=$extra;return json_encode($GLOBALS['receipt']);}
      ${receiptFunctions}
      try{deployEventReceipt('{{current_path}}',${boundRevision ? `'${verified.revision}'` : 'null'});echo 'ACCEPT';}catch(RuntimeException $e){if($e->getMessage()!=='EVENT_RECEIPT_INVALID'){exit(3);}echo 'REJECT';}
      `;
    writeFileSync(script,setup);
    try {
      const result=spawnSync('php',[script,JSON.stringify(receipt)],{encoding:'utf8',timeout:10000});
      assert.equal(result.status,0,result.stderr);
      assert.equal(result.stdout,accepted?'ACCEPT':'REJECT');
      assert.equal(result.stderr,'');
      assert.equal(existsSync(join(root,`event-ingest-runtime-production-${verified.revision}.json`)),accepted,'failure wrote receipt');
    } finally {rmSync(root,{recursive:true,force:true});}
  });
}


const preparation = deploy.slice(deploy.indexOf("task('prepare:release-bootstrap-cache-access'" )).split("\ntask('")[0];
for (const [name,intent,currentManaged,candidateManaged,rollback,managed] of [
  ['first enable','1',false,false,'',true], ['first disable','0',false,false,'',true],
  ['inherit','',true,false,'',true], ['new authority','',false,true,'',true],
  ['LKG','',false,false,'/fixture/lkg.json',true], ['legacy','',false,false,'',false],
]) {
  test(`EVENT actual candidate directory preparation ${name}`,()=>{
    const root=mkdtempSync(join(tmpdir(),'event-prepare-'));
    const script=join(root,'fixture.php');
    writeFileSync(script,`<?php
      $GLOBALS['commands']=[];$GLOBALS['helper']=[];
      putenv('EVENT_INGEST_ENABLED=${intent}');putenv('EVENT_INGEST_RUNTIME_ROLLBACK_SOURCE=${rollback}');
      function task($name,$callback){$callback();}
      function currentHost(){return new class {function getRemoteUser(){return 'fixture';}};}
      function deployOwnerGroupArg($owner,$group){return escapeshellarg($owner.':'.$group);}
      function deployPlaceholderPathArg($root,$relative=''){return escapeshellarg($root.($relative==='' ? '' : '/'.$relative));}
      function deployEventManaged($path){return $path==='{{current_path}}' ? ${currentManaged?'true':'false'} : ${candidateManaged?'true':'false'};}
      function deployEventRuntime($command,$root){$GLOBALS['helper'][]=[$command,$root];}
      function run($command){$GLOBALS['commands'][]=$command;}
      ${preparation}
      echo json_encode(['commands'=>$GLOBALS['commands'],'helper'=>$GLOBALS['helper']]);`);
    try {
      const result=spawnSync('php',[script],{encoding:'utf8',timeout:10000});
      assert.equal(result.status,0,result.stderr);
      const actual=JSON.parse(result.stdout);
      assert.deepEqual(actual.helper,managed?[['prepare','{{release_path}}']]:[]);
      assert.equal(actual.commands.length,managed?4:1);
      for (const [i,relative] of ['', 'backend', 'backend/bootstrap'].entries()) {
        if (managed) {
          const path=`'{{release_path}}${relative?'/' + relative:''}'`;
          assert.equal(actual.commands[i],`test -d ${path} && test ! -L ${path} && sudo -n /usr/bin/chmod 2755 ${path}`);
        }
      }
      assert.match(actual.commands.at(-1),new RegExp(`sudo -n /usr/bin/chmod ${managed?'2755':'2775'} `));
      assert.doesNotMatch(actual.commands.join('\n'),/\.event-ingest|chmod -R|chown -R/);
    } finally {rmSync(root,{recursive:true,force:true});}
  });
}
