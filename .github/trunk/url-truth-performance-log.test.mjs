import {test} from 'node:test';
import assert from 'node:assert/strict';
import {readFileSync,mkdtempSync,writeFileSync,rmSync} from 'node:fs';
import {tmpdir} from 'node:os';
import {join} from 'node:path';
import {spawnSync} from 'node:child_process';

const source=readFileSync(new URL('../../deploy.php',import.meta.url),'utf8');
const helper=source.match(/function emitUrlTruthPerformance\(string \$output\): void\n\{[\s\S]*?\n\}/)?.[0];
const wrapper=source.match(/function runUrlTruthWithPerformance\(string \$command\): void\n\{[\s\S]*?\n\}/)?.[0];
const task=source.split("task('seo:url-truth-controlled-reconcile',")[1].split("task('seo:url-truth-incremental-cms-canary',")[0];
const shell=task.match(/<<<'BASH'\n([\s\S]*?)\nBASH\);/)[1];
const receipt={schema_version:'seo-platform-controlled-url-truth-reconciliation.v1',status:'success',mode:'controlled_write',
  writes_attempted:true,writes_committed:true,artifact:{record_count:1,artifact_hash:'a'.repeat(64)},
  plan:{plan_hash:'b'.repeat(64)},batches:[{database_readback_ok:true}],
  boundaries:{search_submission_allowed:false,hard_delete:false,backend_cms_authority_only:true,cms_write:false,content_publication:false,sitemap_authority_write:false},
  idempotent_rerun:{added:0,duplicate:0,unexpected_updated:0,private_leakage:0,current_binding_conflicts:0,passed:true},
  sitemap_authority_detector:{status:'success',sitemap_without_authority_count:0}};

for(const failure of ['none','execute','plan']) test(`actual deploy shell separates diagnostics and retains ${failure} outcome`,()=>{
  const dir=mkdtempSync(join(tmpdir(),'url-truth-log-'));
  try{
    const stub=join(dir,'artisan.php');
    writeFileSync(stub,`<?php
$execute=in_array('--execute',$argv,true);
fwrite(STDERR,'url_truth_source_performance={"elapsed_ms":1,"record_count":1}'.PHP_EOL);
fwrite(STDERR,'url_truth_performance={"truth_query_count":4}'.PHP_EOL);
$r=json_decode(${JSON.stringify(JSON.stringify(receipt))},true);
if(getenv('TRUTH_FAILURE')==='plan'&&!$execute)$r['plan']['plan_hash']='bad';
if(getenv('TRUTH_FAILURE')==='execute'&&$execute){$r['status']='blocked';$r['writes_committed']=false;}
echo json_encode($r);
exit(getenv('TRUTH_FAILURE')==='execute'&&$execute?7:0);
`);
    const script=shell.replaceAll('{{bin/php}} artisan',`php '${stub}'`).replaceAll('{{bin/php}}','php');
    const run=spawnSync('bash',['-c',script],{encoding:'utf8',env:{...process.env,TRUTH_FAILURE:failure}});
    assert.equal(run.status,failure==='none'?0:failure==='plan'?41:43,run.stderr);
    assert.equal(run.stderr,'');
    assert.match(run.stdout,/url_truth_performance=/);
    const filtered=spawnSync('php',['-r',`
namespace Deployer\\Exception {
class RunException extends \\RuntimeException {
public function __construct(private $host, private $command, private $exitCode, private $output, private $errorOutput){parent::__construct('failure',$exitCode);}
public function getHost(){return $this->host;} public function getCommand(){return $this->command;}
public function getExitCode(){return $this->exitCode;} public function getOutput(){return $this->output;} public function getErrorOutput(){return $this->errorOutput;}
}}
namespace Deployer {
function writeln($line){echo $line.PHP_EOL;}
function run($command,$timeout){
if($timeout!==1800)throw new \\LogicException('timeout changed');
$output=stream_get_contents(STDIN);
if((int)getenv('TRUTH_STATUS')!==0)throw new \\Deployer\\Exception\\RunException('original-host','original-command',(int)getenv('TRUTH_STATUS'),$output,'other stderr');
return $output;
}
${helper} ${wrapper}
try {runUrlTruthWithPerformance('original-command');}
catch(\\Deployer\\Exception\\RunException $e){
echo 'test_exception='.json_encode([$e->getHost(),$e->getCommand(),$e->getExitCode(),$e->getCode(),$e->getOutput(),$e->getErrorOutput()]).PHP_EOL;
}
}`],{encoding:'utf8',input:run.stdout+'\nnot_a_diagnostic=hidden\n',env:{...process.env,TRUTH_STATUS:String(run.status)}});
    assert.equal(filtered.status,0,filtered.stderr);
    const lines=filtered.stdout.trim().split('\n');
    if(run.status!==0){
      const exception=JSON.parse(lines.pop().replace('test_exception=',''));
      assert.deepEqual(exception,['original-host','original-command',run.status,run.status,'','']);
    }
    assert.ok(lines.every(line=>/^url_truth_(?:source_)?performance=/.test(line)));
    assert.doesNotMatch(filtered.stdout,/writes_committed|schema_version|not_a_diagnostic|other stderr/);
  }finally{rmSync(dir,{recursive:true,force:true});}
});
