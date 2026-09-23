import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { execFileSync } from 'node:child_process';
import test from 'node:test';

const workflow = readFileSync(new URL('../workflows/deploy.yml', import.meta.url), 'utf8');
const deploy = readFileSync(new URL('../../deploy.php', import.meta.url), 'utf8');
const publisher = readFileSync(new URL('../../backend/app/Domain/Career/Display/CareerCurrentAuthorityPublisher.php', import.meta.url), 'utf8');
const parity = readFileSync(new URL('../../backend/app/Domain/Career/Display/CareerCurrentAuthorityParity.php', import.meta.url), 'utf8');
const responseCache = readFileSync(new URL('../../backend/app/Services/Career/PublicCareerAuthorityResponseCache.php', import.meta.url), 'utf8');
const ci = readFileSync(new URL('../workflows/ci.yml', import.meta.url), 'utf8');

test('content-only policy is receipt-bound and selects the dedicated deploy task', () => {
  assert.match(workflow, /career_content_only: \$\{\{ steps\.receipt\.outputs\.career_content_only \}\}/);
  assert.match(workflow, /\.career_content_change\.head_sha == \$sha/);
  assert.match(workflow, /\.career_content_change\.base_sha == \.classification\.scope\.validation_base_sha/);
  assert.match(workflow, /deploy_task=deploy:career-content-only/);
  assert.match(workflow, /deploy_mode=career_content_only/);
  assert.match(workflow, /a08-evidence:[\s\S]*?needs\.policy\.outputs\.career_content_only != 'true'/);
  assert.match(workflow, /CAREER_CURRENT_PUBLISH_CHANGED_PAGES_FILE_SHA256=/);
  assert.match(workflow, /CAREER_CURRENT_PUBLISH_CHANGED_PAGE_SET_SHA256=/);
  assert.match(workflow, /Download exact CI validation receipt for Career publisher\n\s+if: needs\.policy\.outputs\.career_package == 'true'/);
  assert.match(workflow, /run-id: \$\{\{ github\.event\.workflow_run\.id \}\}/);
  assert.match(workflow, /Expected one \$\{expected\} artifact/);
  assert.match(workflow, /career_package_artifact_id/);
  assert.match(workflow, /CAREER_CURRENT_PUBLISH_CONTENT_PACKAGE_SHA256=/);
});

test('dedicated mode preserves parity and atomic publish while excluding unrelated runtime work', () => {
  assert.match(deploy, /task\('deploy:career-content-only',[\s\S]*?'artisan:config:cache'[\s\S]*?'deploy:publish'/);
  assert.match(deploy, /after\('seo:competitive-evidence-preactivation', 'career:current-authority-production-preactivation-parity'\)/);
  assert.match(deploy, /after\('deploy:symlink', 'reload:php-fpm'\)/);
  assert.match(deploy, /Skip queue worker reload for Career body-only release/);
  assert.match(deploy, /Skip scheduler installation for Career body-only release/);
  assert.match(deploy, /Skip URL Truth probe because the Career URL set is unchanged/);
  assert.match(deploy, /task\('healthcheck:career-content-only'/);
  assert.match(workflow, /deploy_task=deploy:career-first-publish/);
  assert.match(deploy, /task\('deploy:career-first-publish',[\s\S]*?'prepare:release-bootstrap-cache-access'[\s\S]*?'deploy:publish'/);
  assert.match(deploy, /function deploySkipsAuthorityMutations\(\)[\s\S]*?'career_first_publish'/);
  assert.match(deploy, /task\('career:staging-accountant-'\.\$accountantOperation,[\s\S]*?deployUsesCareerContentPackage\(\)/);
  assert.match(deploy, /before\('healthcheck:sitemap-source', 'seo:warm-sitemap-source-cache-first-publish'\)/);
  assert.match(deploy, /career_content_materialization=incremental/);
  assert.match(deploy, /career_content_materialization=full_fallback/);
  assert.match(deploy, /\.before_sha256/);
  assert.match(deploy, /cp -a "\\\$current\/\."/);
});

test('CI builds one deterministic SHA-bound package and deploy stages consume it', () => {
  assert.match(ci, /Build deterministic exact-SHA Career content package/);
  assert.match(ci, /tar --sort=name --format=ustar --mtime=@0 --owner=0 --group=0 --numeric-owner/);
  assert.doesNotMatch(ci, /career-content-package-repeat\.tar\.gz/);
  assert.match(ci, /career_content_package:\$career_package/);
  assert.match(workflow, /Download the bound Career content package/);
  assert.match(workflow, /Download the bound production Career content package/);
  assert.match(workflow, /Stream Career Current publisher and validate receipt/);
});

test('remote extraction runs the same executable tar validator against generated archives', () => {
  assert.match(deploy, /upload\(__DIR__\.'\/backend\/scripts\/deploy\/extract_career_content_package.py'/);
  assert.match(deploy, /python3 "\\\$extractor" --archive "\\\$archive" --destination "\\\$package_dir"/);
  execFileSync('python3', [new URL('../../backend/scripts/deploy/test_extract_career_content_package.py', import.meta.url).pathname], {
    env: { ...process.env, PYTHONDONTWRITEBYTECODE: '1' },
  });
});

test('publisher batches full readback and fails closed on out-of-set drift', () => {
  assert.match(publisher, /array_chunk\(\$identities, 64\)/);
  assert.match(publisher, /PublicProjectionCache::many\(array_column\(\$candidates, 'key'\)\)/);
  assert.match(publisher, /CURRENT_UNCHANGED_FILE_PAGE_DRIFT/);
  assert.match(publisher, /changed_locale_page_set_sha256/);
});

test('production parity pipelines Redis memory inspection in bounded batches', () => {
  assert.match(parity, /array_chunk\(\$cacheKeys, 256\)/);
  assert.match(parity, /\$connection->pipeline/);
  assert.doesNotMatch(parity, /\$this->memoryUsage\(\$key\)/);
});

test('cache coverage batches pointer payload and legacy reads without retaining page bodies', () => {
  assert.match(responseCache, /array_chunk\(\$targets, 256\)/);
  assert.match(responseCache, /Cache::many/);
  assert.match(responseCache, /'payload' => \$includePayload \? \$payload : null/);
});

// Execute the real task bodies with remote I/O stubbed, not just source regexes.
function coverageTasks(options = {}) {
  const body = name => {
    const start = deploy.indexOf(`task('${name}', function () {`);
    assert.notEqual(start, -1);
    const from = deploy.indexOf('{', start) + 1;
    return deploy.slice(from, deploy.indexOf('\n});', from));
  };
  const input = Buffer.from(JSON.stringify(options)).toString('base64');
  return JSON.parse(execFileSync('php', ['-r', `
namespace Deployer;
$input = json_decode(base64_decode('${input}'), true);
$config = ['release_path' => '/fixture/candidate'];
$commands = [];
$host = $input['host'] ?? 'production';
function get($key, $default = null) { return $GLOBALS['config'][$key] ?? $default; }
function set($key, $value) { $GLOBALS['config'][$key] = $value; }
function currentHost() { return new class { function getAlias() { return $GLOBALS['host']; } }; }
function deploySkipsAuthorityMutations() { return $GLOBALS['input']['skip'] ?? false; }
function deployCareerDetailMinimumTargets($host) { return 1; }
function deployPlaceholderPathArg($root, $suffix) { return $root.'/'.$suffix; }
function writeln($message) {}
function run($command) {
    $GLOBALS['commands'][] = $command;
    return json_encode($GLOBALS['input']['report'] ?? []);
}
$repair = function () { ${body('career:repair-published-detail-cache-coverage')} };
$guard = function () { ${body('guard:career-detail-cache-coverage')} };
$error = null;
try {
    if (! ($input['standalone'] ?? false)) { $repair(); }
    if ($input['other_release'] ?? false) { set('release_path', '/fixture/other'); }
    if ($input['other_host'] ?? false) { $host = 'staging'; }
    $guard();
    if ($input['second_guard'] ?? false) { $guard(); }
} catch (\\Throwable $e) { $error = $e->getMessage(); }
echo json_encode(['commands' => $commands, 'error' => $error, 'coverage' => get('career_detail_post_repair_coverage')]);
`], { encoding: 'utf8' }));
}

function coverageReport(slugs = 1046, writes = false) {
  return {
    contract_version: 'career.job_detail_cache_coverage.v1', status: 'sync_repair_completed',
    coverage_status: 'ready', locales: ['en', 'zh-CN'], locale_count: 2,
    published_slug_count: slugs, expected_target_count: slugs * 2,
    excluded_count: 2, eligible_target_count: slugs * 2 - 2, covered_target_count: slugs * 2 - 2,
    missing_count: 0, broken_count: 0, minimum_target_count: 1, minimum_target_count_met: true,
    repair: { write_executed: writes },
  };
}

test('standard deploy consumes complete post-repair coverage once without a redundant remote scan', () => {
  for (const host of ['production', 'staging']) {
    for (const writes of [false, true]) {
      const result = coverageTasks({ host, report: coverageReport(host === 'production' ? 1046 : 30, writes) });
      assert.equal(result.error, null);
      assert.equal(result.commands.length, 1);
      assert.match(result.commands[0], /--repair-missing-sync/);
      assert.equal(result.coverage, null);
    }
  }
  assert.match(deploy, /after\('career:repair-published-detail-cache-coverage', 'guard:career-detail-cache-coverage'\)/);
});

test('standalone and repeated coverage guards still perform live read-only verification', () => {
  const standalone = coverageTasks({ standalone: true });
  assert.equal(standalone.error, null);
  assert.equal(standalone.commands.length, 1);
  assert.match(standalone.commands[0], /--verify-only/);
  const repeated = coverageTasks({ report: coverageReport(), second_guard: true });
  assert.equal(repeated.error, null);
  assert.equal(repeated.commands.length, 2);
  assert.match(repeated.commands[1], /--verify-only/);
});

test('incomplete or differently bound post-repair coverage fails closed', () => {
  for (const change of [
    { contract_version: 'wrong' }, { status: 'sync_repair_incomplete' }, { coverage_status: 'incomplete' },
    { locales: ['en'] }, { locale_count: 1 }, { published_slug_count: 1045 },
    { expected_target_count: 60 }, { eligible_target_count: 0 }, { covered_target_count: 1 },
    { excluded_count: -1 }, { missing_count: 1 }, { broken_count: 1 },
    { minimum_target_count: 0 }, { minimum_target_count_met: false },
  ]) {
    const result = coverageTasks({ report: { ...coverageReport(), ...change } });
    assert.ok(result.error, JSON.stringify(change));
    assert.equal(result.commands.length, 1);
    assert.equal(result.coverage, null);
  }
  for (const change of [{ other_release: true }, { other_host: true }]) {
    const result = coverageTasks({ report: coverageReport(), ...change });
    assert.ok(result.error);
    assert.equal(result.commands.length, 1);
  }
});

test('content-only and other non-mutating deploy modes retain the existing skip', () => {
  const result = coverageTasks({ skip: true });
  assert.equal(result.error, null);
  assert.deepEqual(result.commands, []);
});
