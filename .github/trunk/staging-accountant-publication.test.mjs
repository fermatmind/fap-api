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
