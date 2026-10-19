import { test } from 'node:test';
import assert from 'node:assert/strict';
import { createHash } from 'node:crypto';
import { downloadProductionArtifact } from './production-evidence.mjs';

const bytes = Buffer.from('immutable artifact fixture');
const artifact = {id: 1, expired: false, digest: `sha256:${createHash('sha256').update(bytes).digest('hex')}`};
const httpError = status => Object.assign(new Error('GitHub read failed'), {stderr: Buffer.from(`gh: HTTP ${status}`)});

test('transient artifact errors retry only the read and verify the final bytes', () => {
  let calls = 0; const waits = [];
  const actual = downloadProductionArtifact('owner/repo', artifact, {
    request: () => { if (++calls < 3) throw httpError(503); return bytes; },
    wait: delay => waits.push(delay),
  });
  assert.equal(actual, bytes);
  assert.equal(calls, 3);
  assert.deepEqual(waits, [1000, 2000]);
});

test('persistent service failure is bounded; auth and missing evidence fail immediately', () => {
  for (const [status, expected] of [[503, 3], [502, 3], [429, 3], [401, 1], [403, 1], [404, 1]]) {
    let calls = 0;
    assert.throws(() => downloadProductionArtifact('owner/repo', artifact, {
      request: () => { calls++; throw httpError(status); }, wait: () => {},
    }), /GitHub read failed/);
    assert.equal(calls, expected);
  }
});

test('expired or malformed authority never downloads; corrupt bytes never retry', () => {
  for (const invalid of [{...artifact, expired: true}, {...artifact, digest: 'invalid'}]) {
    assert.throws(() => downloadProductionArtifact('owner/repo', invalid, {request: () => assert.fail('must not download')}), /Invalid production artifact digest/);
  }
  let calls = 0;
  assert.throws(() => downloadProductionArtifact('owner/repo', artifact, {
    request: () => { calls++; return Buffer.from('corrupt'); }, wait: () => assert.fail('must not retry corruption'),
  }), /digest mismatch/);
  assert.equal(calls, 1);
});
