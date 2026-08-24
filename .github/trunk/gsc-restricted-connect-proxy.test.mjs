import assert from 'node:assert/strict';
import { readFileSync } from 'node:fs';
import test from 'node:test';

import {
  isAllowedTarget,
  parseConnectTarget,
} from '../../backend/scripts/seo/gsc_restricted_connect_proxy.mjs';

test('allows only the readonly GSC OAuth and Search Console HTTPS targets', () => {
  assert.equal(isAllowedTarget(parseConnectTarget('CONNECT oauth2.googleapis.com:443 HTTP/1.1')), true);
  assert.equal(isAllowedTarget(parseConnectTarget('CONNECT searchconsole.googleapis.com:443 HTTP/1.1')), true);
  assert.equal(isAllowedTarget(parseConnectTarget('CONNECT example.com:443 HTTP/1.1')), false);
  assert.equal(isAllowedTarget(parseConnectTarget('CONNECT oauth2.googleapis.com:80 HTTP/1.1')), false);
});

test('rejects malformed CONNECT request lines', () => {
  assert.equal(parseConnectTarget('GET https://oauth2.googleapis.com/token HTTP/1.1'), null);
  assert.equal(parseConnectTarget('CONNECT oauth2.googleapis.com:70000 HTTP/1.1'), null);
  assert.equal(parseConnectTarget('CONNECT user@oauth2.googleapis.com:443 HTTP/1.1'), null);
});

test('nightly runs the active production sync without copying the GSC credential', () => {
  const workflow = readFileSync(new URL('../workflows/nightly.yml', import.meta.url), 'utf8');

  assert.match(workflow, /environment: production/);
  assert.match(workflow, /gsc_restricted_connect_proxy\.mjs/);
  assert.match(workflow, /seo-intel:gsc-sync --window=90 --search-types=web --json/);
  assert.doesNotMatch(workflow, /SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON/);
  assert.doesNotMatch(workflow, /SEO_INTEL_GSC_ACCESS_TOKEN/);
});
