import test from 'node:test';
import assert from 'node:assert/strict';
import { fileURLToPath } from 'node:url';
import { readCandidateRows, verifyOnlineCandidates } from './iq-public-scale-online-qa.mjs';
const backend = fileURLToPath(new URL('../../backend/', import.meta.url));
test('entry read failures identify the surface without leaking transport details or retrying', async () => {
  for (const name of ['TimeoutError', 'AbortError', 'TypeError']) {
    let calls = 0;
    await assert.rejects(verifyOnlineCandidates('staging', backend, async () => {
      calls++;
      const error = new Error('secret https://private.invalid/raw'); error.name = name; throw error;
    }), new RegExp(`^Error: IQ_ONLINE_API_${name === 'TypeError' ? 'TRANSPORT_FAILED' : 'TIMEOUT'}$`));
    assert.equal(calls, 1);
  }
});
test('entry contract errors fail immediately without retrying', async () => {
  let calls = 0;
  await assert.rejects(verifyOnlineCandidates('staging', backend, async () => {
    calls++; return new Response('{}', { status: 404, headers: { 'content-type': 'application/json' } });
  }), /IQ_ONLINE_RESPONSE_INVALID/);
  assert.equal(calls, 1);
});

test('a page timeout is distinguished after exact API candidate readback', async () => {
  const row = readCandidateRows(backend)[0];
  let calls = 0;
  await assert.rejects(verifyOnlineCandidates('staging', backend, async (url) => {
    calls++;
    if (new URL(url).hostname === 'staging-api.fermatmind.com') return new Response(JSON.stringify({
      ok: true, primary_slug: row.identity.slug, scale_code: 'IQ_RAVEN', is_public: true, is_indexable: false,
      content_i18n_json: { [row.key]: row.patch },
    }), { headers: { 'content-type': 'application/json' } });
    const error = new Error('private response'); error.name = 'TimeoutError'; throw error;
  }), /IQ_ONLINE_PAGE_TIMEOUT/);
  assert.equal(calls, 2);
});
