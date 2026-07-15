import { createHash } from 'node:crypto';
import { readFileSync } from 'node:fs';
import { dirname, join } from 'node:path';
import { fileURLToPath } from 'node:url';

const packageDirectory = dirname(fileURLToPath(import.meta.url));
const fixturePath = join(packageDirectory, 'visible-provenance-findings.json');
const fixtureBytes = readFileSync(fixturePath);
const fixture = JSON.parse(fixtureBytes.toString('utf8'));
const expectedHash = readFileSync(join(packageDirectory, 'visible-provenance-findings.sha256'), 'utf8')
  .trim()
  .split(/\s+/u)[0];
const actualHash = createHash('sha256').update(fixtureBytes).digest('hex');

function invariant(condition, message) {
  if (!condition) {
    throw new Error(message);
  }
}

function failCount(field) {
  return fixture.findings.filter(({ assessment }) => assessment[field] === 'FAIL').length;
}

invariant(actualHash === expectedHash, `fixture SHA256 mismatch: ${actualHash}`);
invariant(fixture.schema_version === 'big5-visible-provenance-findings.v1', 'unexpected fixture schema');
invariant(
  fixture.source.artifact_sha256 === '60ec72b708aa5876dbee90ec12fec6dade6387414ce845f2c5ef4e4795b4ac65',
  'runtime closeout source hash changed',
);
invariant(
  fixture.source.selection === 'visible_author == FAIL || visible_reviewer == FAIL || visible_source == FAIL',
  'fixture selection changed',
);
invariant(fixture.counts.unique_findings === 11 && fixture.findings.length === 11, 'expected exactly 11 findings');
invariant(new Set(fixture.findings.map(({ asset_id: assetId }) => assetId)).size === 11, 'asset ids must be unique');
invariant(new Set(fixture.findings.map(({ route }) => route)).size === 11, 'routes must be unique');
invariant(failCount('visible_author') === 3 && fixture.counts.visible_author_fail === 3, 'visible-author count changed');
invariant(failCount('visible_reviewer') === 7 && fixture.counts.visible_reviewer_fail === 7, 'visible-reviewer count changed');
invariant(failCount('visible_source') === 3 && fixture.counts.visible_source_fail === 3, 'visible-source count changed');
invariant(
  fixture.findings.every(({ assessment }) =>
    ['visible_author', 'visible_reviewer', 'visible_source'].some((field) => assessment[field] === 'FAIL')),
  'fixture contains a row outside the fail-only union',
);
invariant(
  !fixtureBytes.toString('utf8').includes('/Users/') && !fixtureBytes.toString('utf8').includes('/private/'),
  'fixture leaks a private local path',
);

console.log(`visible-provenance package ok findings=11 sha256=${actualHash}`);
