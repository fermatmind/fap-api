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
  assert.match(workflow, /SEO_INTEL_ALLOW_EXTERNAL_API_CALLS=true php artisan seo-intel:gsc-sync --window=90 --search-types=web --full-window --trigger=scheduled --json/);
  assert.equal((workflow.match(/SEO_INTEL_ALLOW_EXTERNAL_API_CALLS=true/g) ?? []).length, 1);
  assert.match(workflow, /\.fetch_mode == "full_window"/);
  assert.match(workflow, /unmapped_classification: \$sync\[0\]\.unmapped_classification/);
  assert.match(workflow, /issue_clusters: \$sync\[0\]\.issue_clusters/);
  assert.match(workflow, /schema_version: "gsc-read-model-nightly-sync\.v2"/);
  assert.match(workflow, /external_api_scope: "nightly_restricted_proxy_only"/);
  assert.match(workflow, /credential_copied_to_runner: false/);
  assert.match(workflow, /- name: Build sanitized GSC sync receipt\n\s+if: always\(\)/);
  assert.match(workflow, /- name: Upload sanitized GSC sync receipt\n\s+if: always\(\)/);
  assert.doesNotMatch(workflow, /SEO_INTEL_GSC_SERVICE_ACCOUNT_JSON/);
  assert.doesNotMatch(workflow, /SEO_INTEL_GSC_ACCESS_TOKEN/);
});
