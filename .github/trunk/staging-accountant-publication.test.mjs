import { test } from 'node:test';
import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import { classifyPaths } from './classify-paths.mjs';

const deploy = readFileSync('deploy.php', 'utf8');
const script = readFileSync('backend/scripts/deploy/publish_staging_accountant.php', 'utf8');
test('staging accountant publication runs after activation and is restored on deploy failure', () => {
  const task = deploy.slice(deploy.indexOf("foreach (['publish', 'rollback'] as $accountantOperation)"), deploy.indexOf("task('healthcheck:staging-big-five-report-delivery'"));
  assert.match(task, /currentHost\(\)->getAlias\(\) !== 'staging'/);
  assert.doesNotMatch(task, /sudo|chmod|chown/);
  assert.match(task, /DEPLOY_REVISION/);
  assert.ok(deploy.includes("after('healthcheck:staging-big-five-report-delivery', 'career:staging-accountant-publish');"));
  assert.ok(deploy.includes("after('deploy:failed', 'career:staging-accountant-rollback');"));
  assert.match(script, /staging-api\.fermatmind\.com/);
  assert.match(script, /withoutRedirecting\(\)/);
  assert.match(script, /CareerStagingAccountantPublication::assertResponse/);
  assert.doesNotMatch(script, /withoutVerifying|retry\(/);
});

test('publication and generation changes select the existing controlled cache checks', () => {
  for (const path of ['backend/app/Domain/Career/Publish/CareerStagingAccountantPublication.php', 'backend/app/Domain/Career/Publish/CareerGenerationAuthorityLoader.php', 'backend/scripts/deploy/publish_staging_accountant.php']) {
    const result = classifyPaths([path]);
    assert.equal(result.flags.cache_runtime_projection, true);
    assert.equal(result.deploy, true);
  }
});

test('legacy directory verification retains its bilingual checks and scopes the separate file-page smoke to staging', () => {
  const gate = readFileSync('backend/scripts/deploy/verify_career_cold_cache_discoverability.php', 'utf8');
  assert.ok(gate.includes("if ($app->environment('staging'))"));
  assert.ok(gate.includes('CareerStagingAccountantPublication::hasDedicatedStagingSmoke($item)'));
  assert.ok(gate.includes("self::fail($safePrefix.'_BILINGUAL_SET_MISMATCH')"));
  assert.ok(deploy.includes("after('healthcheck:staging-big-five-report-delivery', 'career:staging-accountant-publish');"));
});

 test('actor publication shares the existing step and verifies its actual deployed web renderer', () => {
  assert.match(script, /foreach \(CareerStagingAccountantPublication::SLUGS as \$slug\)/);
  assert.match(script, /array_reverse\(CareerStagingAccountantPublication::SLUGS\)/);
  assert.match(script, /CareerStagingAccountantPublication::assertWebResponse/);
  assert.match(script, /staging\.fermatmind\.com\/revision/);
  assert.match(script, /staging\.fermatmind\.com\/zh\/career\/jobs\/actors/);
});
