import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
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
  assert.match(workflow, /CAREER_CURRENT_PUBLISH_CHANGED_PAGES_BASE64=/);
  assert.match(workflow, /CAREER_CURRENT_PUBLISH_CHANGED_PAGE_SET_SHA256=/);
  assert.match(workflow, /Download exact CI validation receipt\n\s+if: env\.CAREER_CONTENT_ONLY == 'true'/);
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
  assert.match(workflow, /deploy_task=deploy\n/);
  assert.match(deploy, /career_content_materialization=incremental/);
  assert.match(deploy, /career_content_materialization=full_fallback/);
  assert.match(deploy, /\.before_sha256/);
  assert.match(deploy, /cp -a "\\\$current\/\."/);
});

test('CI builds one deterministic SHA-bound package and deploy stages consume it', () => {
  assert.match(ci, /Build deterministic exact-SHA Career content package/);
  assert.match(ci, /tar --sort=name --format=ustar --mtime=@0 --owner=0 --group=0 --numeric-owner/);
  assert.match(ci, /cmp "\$artifact\/career-content-package\.tar\.gz"/);
  assert.match(ci, /career_content_package:\$career_package/);
  assert.match(workflow, /Download the bound Career content package/);
  assert.match(workflow, /Download the bound production Career content package/);
  assert.match(workflow, /Download exact Career content package for publisher/);
});

test('remote package traversal guard accepts tar directory entries without weakening rejection', () => {
  const guard = deploy.match(/normalized="\\\$\{entry#\.\/\}"[\s\S]*?done < <\(tar -tzf "\\\$archive"\)/)?.[0] ?? '';
  assert.match(guard, /path_for_check="\\\$\{normalized%\/\}"/);
  assert.match(guard, /if \[ -n "\\\$path_for_check" \]; then/);
  assert.ok(guard.includes('case "/\\$path_for_check/" in *\'/../\'*|*\'//\'*)'));
  assert.ok(!guard.includes('case "/\\$normalized/" in *\'/../\'*|*\'//\'*)'));
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
