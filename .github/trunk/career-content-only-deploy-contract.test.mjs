import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

const workflow = readFileSync(new URL('../workflows/deploy.yml', import.meta.url), 'utf8');
const deploy = readFileSync(new URL('../../deploy.php', import.meta.url), 'utf8');
const publisher = readFileSync(new URL('../../backend/app/Domain/Career/Display/CareerCurrentAuthorityPublisher.php', import.meta.url), 'utf8');
const parity = readFileSync(new URL('../../backend/app/Domain/Career/Display/CareerCurrentAuthorityParity.php', import.meta.url), 'utf8');
const responseCache = readFileSync(new URL('../../backend/app/Services/Career/PublicCareerAuthorityResponseCache.php', import.meta.url), 'utf8');

test('content-only policy is receipt-bound and selects the dedicated deploy task', () => {
  assert.match(workflow, /career_content_only: \$\{\{ steps\.receipt\.outputs\.career_content_only \}\}/);
  assert.match(workflow, /\.career_content_change\.head_sha == \$sha/);
  assert.match(workflow, /\.career_content_change\.base_sha == \.classification\.scope\.validation_base_sha/);
  assert.match(workflow, /deploy_task=deploy:career-content-only/);
  assert.match(workflow, /deploy_mode=career_content_only/);
  assert.match(workflow, /a08-evidence:[\s\S]*?needs\.policy\.outputs\.career_content_only != 'true'/);
  assert.match(workflow, /CAREER_CURRENT_PUBLISH_CHANGED_PAGES_BASE64=/);
  assert.match(workflow, /CAREER_CURRENT_PUBLISH_CHANGED_PAGE_SET_SHA256=/);
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
});

test('publisher batches full readback and fails closed on out-of-set drift', () => {
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
