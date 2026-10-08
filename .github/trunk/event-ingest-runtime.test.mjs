import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import { mkdtempSync, writeFileSync, rmSync } from 'node:fs';
import { tmpdir } from 'node:os';
import { join, resolve } from 'node:path';
import test from 'node:test';

const helper = resolve('backend/scripts/deploy/event_ingest_runtime.php');
const fixtureToken = 'public_fixture_only_0123456789abcdef';

function execute(scenario) {
  const root = mkdtempSync(join(tmpdir(), 'event-runtime-fixture-'));
  const php = join(root, 'fixture.php');
  writeFileSync(php, `<?php
// Match the production CLI's bounded error handling; never let a runner's
// Xdebug configuration render input arguments from an uncaught exception.
set_exception_handler(static function (Throwable $error): void {
    $code = $error->getMessage();
    fwrite(STDERR, preg_match('/^EVENT_[A-Z_]+$/', $code) ? $code."\\n" : "EVENT_FIXTURE_FAILED\\n");
    exit(1);
});
require $argv[1];
$root = realpath($argv[2]).'/deploy';
$scenario = $argv[3];
$old = str_repeat('a', 40); $new = str_repeat('b', 40);

$token = '${fixtureToken}';
function check($condition, $label) { if (!$condition) { throw new RuntimeException($label); } }
function reject(callable $callback, string $label): void {
    try { $callback(); } catch (Throwable $e) { check(str_starts_with($e->getMessage(), "EVENT_"), "unexpected rejection"); return; }
    throw new RuntimeException($label);
}
function prepareCandidate(string $backend, string $anchor): void {
    // Model the existing privileged Deployer preparation before helper init.
    EventIngestRuntime::prepare($backend, $anchor);
    foreach ([dirname($backend), $backend, $backend.'/bootstrap', $backend.'/bootstrap/cache'] as $path) {
        chmod($path, 02755);
    }
    EventIngestRuntime::init($backend, $anchor);
}
foreach (['old', 'candidate', 'rollback'] as $name) {
    $backend = $root.'/releases/'.$name.'/backend';
    mkdir($backend.'/bootstrap/cache', 0755, true);
    file_put_contents(dirname($backend).'/REVISION', $name === 'candidate' ? $new : $old);
    file_put_contents($backend.'/bootstrap/cache/config.php', '<?php return '.var_export(['app'=>['env'=>'production'], 'fap'=>['events'=>['ingest_token'=>'']]], true).';');
    chmod($backend.'/bootstrap/cache/config.php', 0640);
    mkdir($backend.'/vendor',0755,true);
    file_put_contents($backend.'/vendor/autoload.php', <<<'AUTOLOAD'
<?php
namespace Symfony\\Component\\Console\\Input;
class ArgvInput {}
if (trim((string)@file_get_contents(dirname(__DIR__).'/case.txt')) === 'driver_autoload_exit') { exit(17); }
AUTOLOAD);
    file_put_contents($backend.'/bootstrap/app.php', <<<'APP'
<?php
if (trim((string)@file_get_contents(__DIR__.'/../case.txt')) === 'driver_app_exit') { exit(17); }
return new class(dirname(__DIR__)) {
    private $handler;
    private $events;
    private array $before=[];
    private array $after=[];
    public function __construct(private string $backend) {}
    private function kind(): string { return trim((string)@file_get_contents($this->backend.'/case.txt')); }
    public function make($class) {
        if ($class === 'events') {
            return $this->events ??= new class {
                private array $listeners=[];
                public function listen($class,$callback): void { $this->listeners[$class][]=$callback; }
                public function dispatch($class,$command): void {
                    foreach ($this->listeners[$class] ?? [] as $callback) { $callback((object)['command'=>$command]); }
                }
            };
        }
        if ($this->kind() === 'driver_handler_exit') { exit(17); }
        return $this->handler ??= new class {
            public $callback;
            public function reportable($callback) { $this->callback=$callback; }
        };
    }
    public function beforeBootstrapping($class,$callback): void {
        if ($this->kind() === 'driver_hooks_exit') { exit(17); }
        $this->before[$class][]=$callback;
    }
    public function afterBootstrapping($class,$callback): void { $this->after[$class][]=$callback; }
    public function handleCommand($input): int {
        try {
            foreach (['LoadEnvironmentVariables'=>'env','LoadConfiguration'=>'config','HandleExceptions'=>'exceptions','RegisterFacades'=>'facades','SetRequestForConsole'=>'request','RegisterProviders'=>'register','BootProviders'=>'providers'] as $name=>$kind) {
                $class=implode(chr(92),['Illuminate','Foundation','Bootstrap',$name]);
                foreach ($this->before[$class] ?? [] as $callback) { $callback(); }
                if ($this->kind() === 'boot_'.$kind.'_exit') { exit(17); }
                foreach ($this->after[$class] ?? [] as $callback) { $callback(); }
                if ($this->kind() === 'boot_after_'.$kind.'_exit') { exit(17); }
            }
            $this->events->dispatch('Illuminate\\Console\\Events\\CommandStarting','config:cache');
            if ($this->kind() === 'command_enter_exit') { exit(17); }
            $this->events->dispatch('Illuminate\\Console\\Events\\CommandStarting','config:clear');
            if ($this->kind() === 'command_clear_exit') { exit(17); }
            $this->events->dispatch('Illuminate\\Console\\Events\\CommandFinished','config:clear');
            $result=require $this->backend.'/artisan';
            $this->events->dispatch('Illuminate\\Console\\Events\\CommandFinished','config:cache');
            return is_int($result) ? $result : 0;
        } catch (Throwable $error) { ($this->handler->callback)($error); return 1; }
    }
};
APP);
    file_put_contents($backend.'/artisan', <<<'ARTISAN'
<?php
$kind = @file_get_contents(__DIR__.'/case.txt') ?: '';
$config = ['app'=>['env'=>$kind === 'wrong_environment' ? 'staging' : 'production'], 'fap'=>['events'=>['ingest_token'=>$kind === 'wrong_token' ? 'wrong' : (string) getenv('EVENT_INGEST_TOKEN')]]];
fwrite(STDOUT, getenv('EVENT_INGEST_TOKEN')); fwrite(STDERR, getenv('EVENT_INGEST_TOKEN'));
if ($kind === 'child_failure') { exit(1); }
if ($kind === 'child_exit_other') { exit(17); }
if ($kind === 'child_signal') { posix_kill(getmypid(),15); }
if ($kind === 'child_unreported') { return 1; }
if ($kind === 'progress_invalid_success') { fwrite(fopen('php://fd/3','w'),'public_invalid_progress_fixture'); }
if ($kind === 'progress_invalid') { fwrite(fopen('php://fd/3','w'),'public_invalid_progress_fixture'); exit(17); }
if ($kind === 'progress_overflow') { fwrite(fopen('php://fd/3','w'),str_repeat(chr(1),129)); exit(17); }
if ($kind === 'child_logic') { throw new LogicException(getenv('EVENT_INGEST_TOKEN')); }
if ($kind === 'child_type') { throw new TypeError(getenv('EVENT_INGEST_TOKEN')); }
if ($kind === 'child_database') { throw new PDOException(getenv('EVENT_INGEST_TOKEN')); }
if ($kind === 'child_value') { throw new UnexpectedValueException(getenv('EVENT_INGEST_TOKEN')); }
if ($kind === 'child_runtime') { throw new RuntimeException(getenv('EVENT_INGEST_TOKEN')); }
file_put_contents(getenv('APP_CONFIG_CACHE'), '<?php return '.var_export($config, true).';');
chmod(getenv('APP_CONFIG_CACHE'), 0664);
return 0;
ARTISAN);
}
$current = $root.'/releases/old/backend'; $candidate = $root.'/releases/candidate/backend';

symlink($root.'/releases/old', $root.'/current');
$pointer = readlink($root.'/current'); $cacheHash = hash_file('sha256', $current.'/bootstrap/cache/config.php');
$prepare = function(string $intent='1', string $value='') use ($candidate, $root, $current, $new, $token): bool {
    prepareCandidate($candidate, $root);
    file_put_contents($candidate.'/.event-ingest/incoming.json', json_encode(['intent'=>$intent, 'token'=>$intent==='1' ? ($value ?: $token) : '']));
    chmod($candidate.'/.event-ingest/incoming.json', 0600);
    return EventIngestRuntime::install($candidate, $new, 'production', $current, $root, '');
};
if ($scenario === 'unsafe_parent') {
    chmod($root.'/releases', 0775); reject(fn()=>$prepare(), 'unsafe parent accepted');
} elseif ($scenario === 'missing_token') {
    prepareCandidate($candidate, $root);
    file_put_contents($candidate.'/.event-ingest/incoming.json', json_encode(['intent'=>'1','token'=>'']));
    reject(fn()=>EventIngestRuntime::install($candidate,$new,'production',$current,$root,''), 'missing accepted');
    check(!file_exists($candidate.'/.event-ingest/incoming.json'), 'input retained after failure');
} elseif ($scenario === 'unsafe_current_cache') {
    chmod($current.'/bootstrap/cache/config.php',0664); reject(fn()=>$prepare(),'unsafe LKG accepted');
} elseif ($scenario === 'unmanaged') {
    check(EventIngestRuntime::verify($current,$old,'production',$root)===null,'true legacy changed');
    check(!EventIngestRuntime::managed($current),'legacy marked');
    $cli=proc_open([PHP_BINARY,'-r',"define('FAP_EVENT_RUNTIME_CLI',true);require ".var_export($argv[1],true).";",'--','probe',$current,$old,'production',$root,'0',''],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
    $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    check(proc_close($cli)===0 && $err==='' && json_decode($out,true)['status']==='unmanaged','true legacy CLI incompatible');
} elseif ($scenario === 'unknown_legacy_token') {
    file_put_contents($current.'/bootstrap/cache/config.php','<?php return '.var_export(['app'=>['env'=>'production'],'fap'=>['events'=>['ingest_token'=>$token]]],true).';');
    $cacheHash = hash_file('sha256',$current.'/bootstrap/cache/config.php'); reject(fn()=>$prepare(),'adopted unknown credential');
} else {
    $prepare($scenario === 'disabled' ? '0' : '1');
    if (str_starts_with($scenario,'cli_') || in_array($scenario,['cli_child_failure','cli_unknown_failure','cli_child_logic','cli_child_type','cli_child_database','cli_child_value','cli_child_runtime','cli_child_exit_other','cli_child_signal','cli_child_unreported'])) {
        file_put_contents($candidate.'/case.txt',substr($scenario,4));
        if ($scenario === 'cli_unknown_failure') {
            file_put_contents($candidate.'/.event-ingest/input.json','not JSON '.$token);
        }
        $cli=proc_open([PHP_BINARY,'-r',"define('FAP_EVENT_RUNTIME_CLI',true);require ".var_export($argv[1],true).";",'--','compile',$candidate,$new,'production',$root],[0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        $expected=match($scenario) {'cli_child_failure'=>'EVENT_COMPILE_CHILD_EXIT_ONE','cli_child_logic'=>'EVENT_COMPILE_CHILD_LOGIC','cli_child_type'=>'EVENT_COMPILE_CHILD_TYPE','cli_child_database'=>'EVENT_COMPILE_CHILD_DATABASE','cli_child_value'=>'EVENT_COMPILE_CHILD_VALUE','cli_child_runtime'=>'EVENT_COMPILE_CHILD_RUNTIME','cli_child_exit_other'=>'EVENT_COMPILE_CHILD_EXIT_OTHER','cli_child_signal'=>'EVENT_COMPILE_CHILD_SIGNAL','cli_child_unreported'=>'EVENT_COMPILE_CHILD_UNREPORTED',default=>'EVENT_COMPILE_AUTHORITY'};
        $boundaries=['driver_autoload_exit'=>'AUTOLOAD_ENTER','driver_app_exit'=>'APP_ENTER','driver_handler_exit'=>'HANDLER_ENTER','driver_hooks_exit'=>'HOOKS_ENTER','boot_env_exit'=>'ENV_ENTER','boot_config_exit'=>'CONFIG_ENTER','boot_exceptions_exit'=>'EXCEPTIONS_ENTER','boot_facades_exit'=>'FACADES_ENTER','boot_request_exit'=>'REQUEST_ENTER','boot_register_exit'=>'PROVIDERS_REGISTER_ENTER','boot_providers_exit'=>'PROVIDERS_BOOT_ENTER','boot_after_env_exit'=>'ENV_RETURNED','boot_after_config_exit'=>'CONFIG_RETURNED','command_enter_exit'=>'CONFIG_COMMAND_ENTER','command_clear_exit'=>'CONFIG_CLEAR_ENTER','progress_invalid'=>'PROGRESS_UNKNOWN','progress_overflow'=>'PROGRESS_UNKNOWN'];
        if (array_key_exists(substr($scenario,4),$boundaries)) { $expected='EVENT_COMPILE_CHILD_EXIT_OTHER'; }
        if ($scenario!=='cli_unknown_failure') { $expected.='_AT_'.($scenario==='cli_child_unreported' ? 'HANDLE_RETURNED' : ($boundaries[substr($scenario,4)] ?? 'CONFIG_CLEAR_RETURNED')); }
        check(proc_close($cli)===1 && $out==='' && $err==="EVENT_RUNTIME_FAILED:".$expected."\\n",'CLI error classification');
        check(!str_contains($out.$err,$token),'CLI leaked fixture input');
        $unchanged = require $candidate.'/bootstrap/cache/config.php'; check($unchanged['fap']['events']['ingest_token']==='', 'CLI failed candidate cache changed');
    } elseif (in_array($scenario,['wrong_environment','wrong_token','child_failure'])) {
        file_put_contents($candidate.'/case.txt',$scenario);
        reject(fn()=>EventIngestRuntime::compile($candidate,$new,'production',$root),'bad cache accepted');
        $unchanged = require $candidate.'/bootstrap/cache/config.php'; check($unchanged['fap']['events']['ingest_token']==='', 'failed candidate cache changed');
    } elseif ($scenario === 'unsafe_input') {
        chmod($candidate.'/.event-ingest/input.json',0644); reject(fn()=>EventIngestRuntime::compile($candidate,$new,'production',$root),'unsafe input accepted');
    } elseif ($scenario === 'symlink_input') {
        rename($candidate.'/.event-ingest/input.json',$candidate.'/.event-ingest/source.json');
        symlink($candidate.'/.event-ingest/source.json',$candidate.'/.event-ingest/input.json');
        reject(fn()=>EventIngestRuntime::compile($candidate,$new,'production',$root),'symlink accepted');
    } else {
        if ($scenario === 'progress_invalid_success') { file_put_contents($candidate.'/case.txt',$scenario); }
        check(EventIngestRuntime::compile($candidate,$new,'production',$root),'not compiled');
        $input = EventIngestRuntime::verify($candidate,$new,'production',$root);
        check((fileperms($candidate.'/bootstrap/cache/config.php')&0777)===0640,'cache permissions');
        check((fileperms($candidate.'/.event-ingest/input.json')&0777)===0600,'input permissions');
        if (str_starts_with($scenario, 'lost_')) {
            $target=$candidate;$targetRevision=$new;
            if ($scenario==='lost_rollback') {
                $target=$root.'/releases/rollback/backend';$targetRevision=$old;
                prepareCandidate($target,$root);
                file_put_contents($target.'/.event-ingest/incoming.json',json_encode(['intent'=>'0','token'=>'']));
                EventIngestRuntime::install($target,$old,'production',$candidate,$root,$candidate.'/.event-ingest/lkg.json');
                EventIngestRuntime::compile($target,$old,'production',$root);
            }
            if ($scenario==='lost_postactivation' || $scenario==='lost_rollback') {
                unlink($root.'/current');symlink(dirname($target),$root.'/current');$pointer=readlink($root.'/current');
            }
            unlink($target.'/.event-ingest/input.json');
            check(EventIngestRuntime::managed($target),'input loss removed management');
            $managedCacheHash=hash_file('sha256',$target.'/bootstrap/cache/config.php');
            if ($scenario==='lost_inherit') {
                $next=$root.'/releases/rollback/backend';prepareCandidate($next,$root);
                file_put_contents($next.'/.event-ingest/incoming.json',json_encode(['intent'=>'','token'=>'']));
                reject(fn()=>EventIngestRuntime::install($next,$old,'production',$candidate,$root,''),'input loss inherited as legacy');
            } else {
                reject(fn()=>EventIngestRuntime::verify($target,$targetRevision,'production',$root,true),'lost authority accepted');
                if ($scenario==='lost_prepared' || $scenario==='lost_rebuild') {
                    $GLOBALS['target']=$target;$GLOBALS['targetRevision']=$targetRevision;$GLOBALS['anchor']=$root;
                    $GLOBALS['verify_calls']=0;$GLOBALS['within_calls']=0;
                    function task($name,$callback){$GLOBALS['callback']=$callback;}
                    function deployEventManaged($root){return EventIngestRuntime::managed($GLOBALS['target']);}
                    function deployEventExpectation($root){return [true,null];}
                    function deployEventRuntime($command,$root,$extra=[],$revision=null){$GLOBALS['verify_calls']++;EventIngestRuntime::verify($GLOBALS['target'],$GLOBALS['targetRevision'],'production',$GLOBALS['anchor'],true);return '';}
                    function deployPlaceholderPathArg($root,$relative=''){return escapeshellarg($root.'/'.$relative);}
                    function run($command){return $GLOBALS['targetRevision'];}
                    function test($command){return file_exists($GLOBALS['target'].'/.event-ingest/input.json');}
                    function within(...$arguments){$GLOBALS['within_calls']++;throw new RuntimeException('UNGUARDED_CACHE_MUTATION');}
                    putenv('EVENT_INGEST_ENABLED');putenv('EVENT_INGEST_RUNTIME_ROLLBACK_SOURCE');
                    $code=file_get_contents($argv[4]);$name=$scenario==='lost_prepared' ? 'event-ingest:candidate-verify' : 'bootstrap-cache:rebuild-current';
                    $start=strpos($code,"task('".$name."'");$end=strpos($code,"\ntask('",$start+4);
                    eval(substr($code,$start,$end-$start));
                    reject(fn()=>($GLOBALS['callback'])(),'actual deployment guard skipped');
                    check($GLOBALS['verify_calls']===1 && $GLOBALS['within_calls']===0,'guard did not precede cache mutation');
                }

                $cli=proc_open([PHP_BINARY,'-r',"define('FAP_EVENT_RUNTIME_CLI',true);require ".var_export($argv[1],true).";",'--',
                    $scenario==='lost_rebuild' || $scenario==='lost_prepared' ? 'verify' : 'probe',$target,$targetRevision,'production',$root,'1',''],
                    [0=>['file','/dev/null','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
                $out=stream_get_contents($pipes[1]);$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
                check(proc_close($cli)!==0,'CLI input loss accepted');check($out==='','CLI emitted successful receipt');check($err==="EVENT_RUNTIME_FAILED:EVENT_OTHER_RUNTIME\n",'CLI leaked raw failure');
            }
            check(hash_file('sha256',$target.'/bootstrap/cache/config.php')===$managedCacheHash,'lost input changed cache');
        } elseif ($scenario==='marker_missing') {
            unlink($candidate.'/.event-ingest-managed.json');
            reject(fn()=>EventIngestRuntime::verify($candidate,$new,'production',$root),'missing marker became legacy');
        } elseif ($scenario==='directory_missing') {
            unlink($candidate.'/.event-ingest/input.json');unlink($candidate.'/.event-ingest/lkg.json');rmdir($candidate.'/.event-ingest');
            check(EventIngestRuntime::managed($candidate),'durable public marker lost');
            reject(fn()=>EventIngestRuntime::verify($candidate,$new,'production',$root),'missing directory became legacy');
        } elseif ($scenario==='marker_corrupt') {
            file_put_contents($candidate.'/.event-ingest-managed.json','{}');
            reject(fn()=>EventIngestRuntime::verify($candidate,$new,'production',$root),'corrupt marker accepted');
        } elseif ($scenario==='marker_unsafe') {
            chmod($candidate.'/.event-ingest-managed.json',0666);
            reject(fn()=>EventIngestRuntime::verify($candidate,$new,'production',$root),'unsafe marker accepted');
        } elseif ($scenario === 'wrong_revision') {
            reject(fn()=>EventIngestRuntime::verify($candidate,$old,'production',$root),'wrong revision accepted');
        } elseif ($scenario === 'rollback') {
            $rollback=$root.'/releases/rollback/backend'; prepareCandidate($rollback,$root);
            file_put_contents($rollback.'/.event-ingest/incoming.json',json_encode(['intent'=>'0','token'=>'']));
            EventIngestRuntime::install($rollback,$old,'production',$candidate,$root,$candidate.'/.event-ingest/lkg.json');
            EventIngestRuntime::compile($rollback,$old,'production',$root);
            check(EventIngestRuntime::verify($rollback,$old,'production',$root)['enabled']===false,'old enabled state lost');
        } elseif ($scenario === 'inherit') {
            $next=$root.'/releases/rollback/backend'; file_put_contents(dirname($next).'/REVISION',$old);
            prepareCandidate($next,$root);file_put_contents($next.'/.event-ingest/incoming.json',json_encode(['intent'=>'','token'=>'']));
            EventIngestRuntime::install($next,$old,'production',$candidate,$root,'');EventIngestRuntime::compile($next,$old,'production',$root);
            check(EventIngestRuntime::verify($next,$old,'production',$root)['token']===$token,'ordinary release lost input');
        } else {
            $receipt=EventIngestRuntime::probe($input,function($value) use ($scenario, $token): array {
                if ($scenario==='disabled') { check($value==='','disabled header');return [503,json_encode(['ok'=>false,'error_code'=>'INGEST_DISABLED'])]; }
                check($value===$token,'wrong header');
                return [$scenario==='http200' ? 200 : ($scenario==='http401' ? 401 : 422),json_encode(['ok'=>false,'error_code'=>'VALIDATION_FAILED','details'=>['eventName'=>['required']]])];
            });
            check(!str_contains(json_encode($receipt),$token),'secret in receipt');
            check($receipt['status']==='verified','not verified');
        }
    }
}
check(readlink($root.'/current')===$pointer,'current pointer changed');
check(hash_file('sha256',$current.'/bootstrap/cache/config.php')===$cacheHash,'active cache changed');
echo 'PASS';
`);
  try {
    return spawnSync('php', ['-d', 'display_errors=stderr', php, helper, root, scenario, resolve('deploy.php')], { encoding: 'utf8', timeout: 15000 });
  } finally { rmSync(root, { recursive: true, force: true }); }
}

for (const scenario of ['enabled', 'disabled', 'unmanaged', 'missing_token', 'unsafe_current_cache', 'unsafe_parent', 'unsafe_input', 'symlink_input', 'unknown_legacy_token', 'wrong_environment', 'wrong_token', 'child_failure', 'cli_child_failure', 'cli_unknown_failure', 'cli_child_logic', 'cli_child_type', 'cli_child_database', 'cli_child_value', 'cli_child_runtime', 'cli_child_exit_other', 'cli_child_signal', 'cli_child_unreported', 'cli_driver_autoload_exit', 'cli_driver_app_exit', 'cli_driver_handler_exit', 'cli_driver_hooks_exit', 'cli_boot_env_exit', 'cli_boot_config_exit', 'cli_boot_exceptions_exit', 'cli_boot_facades_exit', 'cli_boot_request_exit', 'cli_boot_register_exit', 'cli_boot_providers_exit', 'cli_boot_after_env_exit', 'cli_boot_after_config_exit', 'cli_command_enter_exit', 'cli_command_clear_exit', 'cli_progress_invalid', 'cli_progress_overflow', 'progress_invalid_success', 'wrong_revision', 'rollback', 'inherit', 'lost_inherit', 'lost_prepared', 'lost_postactivation', 'lost_rebuild', 'lost_rollback', 'marker_missing', 'directory_missing', 'marker_corrupt', 'marker_unsafe']) {
  test(`EVENT candidate/LKG ${scenario}`, {skip: process.platform !== 'linux' ? 'Requires Linux directory setgid inheritance' : false}, () => {
    const result = execute(scenario);
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stdout, 'PASS');
    assert.doesNotMatch(result.stderr + result.stdout, new RegExp(fixtureToken));
  });
}
for (const scenario of ['http200', 'http401']) {
  test(`EVENT empty envelope refuses ${scenario}`, {skip: process.platform !== 'linux' ? 'Requires Linux directory setgid inheritance' : false}, () => {
    const result = execute(scenario);
    assert.notEqual(result.status, 0);
    assert.match(result.stderr, /EVENT_HTTP_CONTRACT_FAILED/);
    assert.doesNotMatch(result.stderr + result.stdout, new RegExp(fixtureToken));
  });
}

const probeCases = [
  ['valid', {eventName: ['The event name field is required.']}, 422, true],
  ['empty', {eventName: []}, 422, false],
  ['scalar', {eventName: 'required'}, 422, false],
  ['null', {eventName: null}, 422, false],
  ['missing', {}, 422, false],
  ['unrelated', {payload: ['required']}, 422, false],
  ['200', {eventName: ['required']}, 200, false],
  ['202', {eventName: ['required']}, 202, false],
  ['401', {eventName: ['required']}, 401, false],
];
for (const [name, details, status, accepted] of probeCases) {
  test(`EVENT F4 exact error array ${name}`, () => {
    const program = `require $argv[1]; $calls=0; $input=EventIngestRuntime::disabled(str_repeat('a',40),'production');$input['enabled']=true;$input['token']='${fixtureToken}';
      try {$receipt=EventIngestRuntime::probe($input,function($token)use(&$calls){$calls++;return [(int)$GLOBALS['argv'][3],json_encode(['ok'=>false,'error_code'=>'VALIDATION_FAILED','details'=>json_decode($GLOBALS['argv'][2],true)])];});
        if($calls!==1){exit(3);}echo 'ACCEPT';}catch(RuntimeException $e){if($calls!==1 || $e->getMessage()!=='EVENT_HTTP_CONTRACT_FAILED'){exit(4);}echo 'REJECT';}`;
    const result = spawnSync('php', ['-r', program, '--', helper, JSON.stringify(details), String(status)], {encoding:'utf8', timeout:10000});
    assert.equal(result.status, 0, result.stderr);
    assert.equal(result.stdout, accepted ? 'ACCEPT' : 'REJECT');
    assert.equal(result.stderr, '');
  });
}
