import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, existsSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import test from 'node:test';

const helper = resolve(process.env.EVENT_LINUX_BASELINE_HELPER || 'backend/scripts/deploy/event_ingest_runtime.php');
const fixture = String.raw`<?php
function check(bool $ok, string $label): void { if (!$ok) { throw new RuntimeException($label); } }
$root = $argv[2].'/deploy';
$old = str_repeat('a',40); $new = str_repeat('b',40);
if ($argv[1] === 'setup') {
    foreach (['old', 'candidate', 'rollback'] as $name) {
        $backend = $root.'/releases/'.$name.'/backend';
        mkdir($backend.'/bootstrap/cache',0755,true);
        file_put_contents(dirname($backend).'/REVISION',$name === 'candidate' ? $new : $old);
        file_put_contents($backend.'/bootstrap/cache/config.php','<?php return '.var_export(['app'=>['env'=>'production'],'fap'=>['events'=>['ingest_token'=>'']]],true).';');
        file_put_contents($backend.'/artisan', <<<'ARTISAN'
<?php
$config=['app'=>['env'=>'production'],'fap'=>['events'=>['ingest_token'=>(string)getenv('EVENT_INGEST_TOKEN')]]];
file_put_contents(getenv('APP_CONFIG_CACHE'),'<?php return '.var_export($config,true).';');
ARTISAN);
    }
    $entries = iterator_to_array(new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST));
    array_unshift($entries,new SplFileInfo($root));
    foreach ($entries as $entry) {
        $path=$entry->getPathname();
        chown($path,60001); chgrp($path,33);
        chmod($path,$entry->isDir() ? 02755 : (str_ends_with($path,'config.php') ? 0640 : 0644));
    }
    symlink($root.'/releases/old',$root.'/current');
    exit;
}
require $argv[3];
check(PHP_OS_FAMILY==='Linux' && posix_geteuid()===60001 && posix_getegid()===60001 && !in_array(33,posix_getgroups(),true),'foreign group fixture identity');
check(preg_match('/^CapEff:\s+0+$/m',file_get_contents('/proc/self/status'))===1,'helper retained capabilities');
$current=$root.'/releases/old/backend'; $candidate=$root.'/releases/candidate/backend';
$pointer=readlink($root.'/current');$hash=hash_file('sha256',$current.'/bootstrap/cache/config.php');
$scenario=$argv[4];
if ($scenario === 'missing_sgid') {
    // Reproduce the silent successful chmod plus SGID clear, without membership.
    check(chmod($candidate.'/bootstrap/cache',02755),'chmod failed');
    clearstatcache();check((fileperms($candidate.'/bootstrap/cache')&02000)===0,'Linux failed to clear foreign-group setgid');
    try { EventIngestRuntime::init($candidate,$root); throw new RuntimeException('missing setgid accepted'); }
    catch (RuntimeException $e) { check($e->getMessage()==='EVENT_CANDIDATE_PERMISSION_FAILED','wrong failure'); }
} elseif ($scenario === 'active_candidate') {
    try { EventIngestRuntime::prepare($current,$root); throw new RuntimeException('active candidate accepted'); }
    catch (RuntimeException $e) { check($e->getMessage()==='EVENT_INACTIVE_CANDIDATE_REQUIRED','wrong active failure'); }
} else {
    EventIngestRuntime::init($candidate,$root);
    if ($scenario === 'baseline') {
        clearstatcache();check((fileperms($candidate.'/bootstrap/cache')&02000)===0,'baseline SGID was retained');
        file_put_contents($candidate.'/.event-ingest/incoming.json',json_encode(['intent'=>'1','token'=>'public_fixture_only_0123456789abcdef']));
        EventIngestRuntime::install($candidate,$new,'production',$current,$root,'');
        try { EventIngestRuntime::compile($candidate,$new,'production',$root); throw new RuntimeException('baseline unexpectedly activated'); }
        catch (RuntimeException $e) { check($e->getMessage()==='EVENT_CACHE_ACTIVATION_FAILED','baseline failed elsewhere'); }
        check(readlink($root.'/current')===$pointer && hash_file('sha256',$current.'/bootstrap/cache/config.php')===$hash,'baseline LKG mutated');
        echo 'PASS';exit;
    }
    clearstatcache();
    check((fileperms($candidate.'/bootstrap/cache')&07777)===02755,'cache setgid lost');
    check((fileperms($candidate.'/.event-ingest')&07777)===02700 && filegroup($candidate.'/.event-ingest')===33,'private inheritance lost');
    if ($scenario === 'wrong_private_group') {
        // The owner may switch to its own group. The helper must reject drift.
        check(chgrp($candidate.'/.event-ingest',60001),'group drift failed');
        chmod($candidate.'/.event-ingest',02700);
        unlink($candidate.'/.event-ingest/incoming.json');
        try { EventIngestRuntime::init($candidate,$root); throw new RuntimeException('private group drift accepted'); }
        catch (RuntimeException $e) { check($e->getMessage()==='EVENT_PRIVATE_DIRECTORY_FAILED','wrong group failure'); }
    } else {
        if ($scenario === 'native_enabled') {
            foreach (['APP_ENV'=>'production','APP_KEY'=>'public_fixture_only_0123456789abc','DB_CONNECTION'=>'sqlite','DB_DATABASE'=>':memory:','CACHE_STORE'=>'array','QUEUE_CONNECTION'=>'sync','TELESCOPE_ENABLED'=>'false','PULSE_ENABLED'=>'false','NIGHTWATCH_ENABLED'=>'false'] as $key=>$value) { putenv($key.'='.$value); }
            putenv('APP_SERVICES_CACHE='.$candidate.'/.event-ingest/services.php');
            putenv('APP_PACKAGES_CACHE='.$candidate.'/.event-ingest/packages.php');
            file_put_contents($candidate.'/artisan','<?php require '.var_export($argv[5],true).';');
        }
        $enabled=$scenario!=='disabled';
        $wire=['intent'=>$enabled?'1':'0','token'=>$enabled?'public_fixture_only_0123456789abcdef':''];
        file_put_contents($candidate.'/.event-ingest/incoming.json',json_encode($wire));
        check(EventIngestRuntime::install($candidate,$new,'production',$current,$root,''),'install failed');
        check(EventIngestRuntime::compile($candidate,$new,'production',$root),'compile failed');
        check(EventIngestRuntime::verify($candidate,$new,'production',$root,true,$enabled)['enabled']===$enabled,'readback failed');
        clearstatcache();
        check(filegroup($candidate.'/bootstrap/cache/config.php')===33 && (fileperms($candidate.'/bootstrap/cache/config.php')&07777)===0640,'compiled group/mode');
        if ($scenario === 'rollback') {
            $target=$root.'/releases/rollback/backend';
            EventIngestRuntime::init($target,$root);
            file_put_contents($target.'/.event-ingest/incoming.json',json_encode(['intent'=>'0','token'=>'']));
            check(EventIngestRuntime::install($target,$old,'production',$candidate,$root,$candidate.'/.event-ingest/lkg.json'),'LKG install');
            check(EventIngestRuntime::compile($target,$old,'production',$root),'LKG compile');
            check(EventIngestRuntime::verify($target,$old,'production',$root,true,false)['enabled']===false,'LKG verify');
        }
    }
}
check(readlink($root.'/current')===$pointer && hash_file('sha256',$current.'/bootstrap/cache/config.php')===$hash,'LKG mutated');
echo 'PASS';
`;

for (const scenario of (process.env.EVENT_LINUX_BASELINE_HELPER ? ['baseline'] : ['enabled','disabled','rollback','native_enabled','missing_sgid','wrong_private_group','active_candidate'])) {
  test(`EVENT Linux foreign-group ${scenario}`, {skip:process.platform !== 'linux' ? 'Requires actual Linux setgid semantics' : scenario === 'native_enabled' && !existsSync('backend/vendor/autoload.php') ? 'Native Laravel fixture requires Composer runtime dependencies' : false}, () => {
    const root=mkdtempSync(join(tmpdir(),'event-linux-groups-'));
    const script=join(root,'fixture.php');
    writeFileSync(script,fixture,{mode:0o644});
    // Only the synthetic /tmp fixture is prepared as root. The helper and its
    // child run without root, supplemental groups, or retained capabilities.
    const privileged=(args)=>spawnSync(process.getuid()===0 ? args[0] : 'sudo',process.getuid()===0 ? args.slice(1) : ['-n',...args],{encoding:'utf8',timeout:scenario==='native_enabled'?60000:15000});
    try {
      const setup=privileged(['php',script,'setup',root]);
      assert.equal(setup.status,0,setup.stderr);
      const ready=privileged(['chmod','0755',root]);assert.equal(ready.status,0,ready.stderr);
      const result=privileged(['setpriv','--reuid=60001','--regid=60001','--clear-groups','--','php',script,'run',root,helper,scenario,resolve('backend/artisan')]);
      assert.equal(result.status,0,result.stderr);
      assert.equal(result.stdout,'PASS');assert.equal(result.stderr,'');
    } finally {
      const clean=privileged(['rm','-rf','--',root]);
      assert.equal(clean.status,0,clean.stderr);
    }
  });
}
