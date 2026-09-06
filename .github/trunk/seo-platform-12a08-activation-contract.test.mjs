import assert from "node:assert/strict";
import test from "node:test";
import { readFileSync } from "node:fs";
import { inRuntimeScope, mayCarry, validateScopedAcceptance } from "./seo-platform-12a08-activation.mjs";

const sha = "a".repeat(40);
const receipt = {
  schema_version: "seo.platform12_a08_staging_acceptance.v2", sha, status: "pass",
  source_connected: true, mission_count: 3, trigger_mode: "controlled_acceptance",
  natural_slot_receipt: false, notification_delivery_verified: true, pause_resume_verified: true,
  receipt_to_ui_verified: true,
  model_calls: 0, tool_calls: 0, external_calls: 0, business_writes: 0,
  deploy_run_id: 12, deploy_run_attempt: 1,
};

test("scoped staging evidence replaces full Nightly as the A08 activation basis", () => {
  assert.equal(validateScopedAcceptance(receipt, sha), true);
  for (const changed of [
    { ...receipt, source_connected: false },
    { ...receipt, natural_slot_receipt: true },
    { ...receipt, business_writes: 1 },
  ]) assert.throws(() => validateScopedAcceptance(changed, sha), /SCOPED_ACCEPTANCE_EVIDENCE_HOLD/);
});

test("runtime compatibility scope is explicit and excludes ordinary content bodies", () => {
  for (const path of [
    "backend/app/Services/SeoCouncil/Platform12/Platform12DailyScheduler.php",
    "backend/app/Services/SeoAgentPolicyGateway/PolicyGatewayRegistry.php",
    "backend/app/Services/SeoAgentEvidence/Privacy/SeoQueryHmac.php",
    "backend/app/Services/SeoIntel/Runtime/ScheduledRuntimeProbeReceiptService.php",
    "backend/app/Services/Ops/OpsAlertService.php", "backend/config/ops.php",
    "backend/config/seo_council.php", "backend/composer.lock", ".github/workflows/deploy.yml",
  ]) assert.equal(inRuntimeScope(path), true, path);
  for (const path of [
    "backend/content_packs/BIG5_OCEAN/v1/compiled/manifest.json",
    "backend/content_assets/career/current/accountants-and-auditors/en/content.json",
    "backend/app/Http/Controllers/API/V0_5/Cms/ArticleController.php",
  ]) assert.equal(inRuntimeScope(path), false, path);
});

test("compatible descendants retain original scoped evidence only when runtime is unchanged", () => {
  const manifest = { schema_version: "seo.platform12_a08_activation.v2", repository: "fermatmind/fap-api",
    activation_basis: "A08_SCOPED_READ_ONLY_ACCEPTANCE", activation_state: "ACTIVE_READ_ONLY",
    bound_production_sha: sha, compatibility: { fingerprint: { scope_version: "seo-council-a08-runtime.v2",
      sha256: "b".repeat(64), file_count: 42 } }, runtime: { version_vector: { policy: "c".repeat(64) } } };
  const candidate = { production_sha: "d".repeat(40), fingerprint: manifest.compatibility.fingerprint,
    version_vector: manifest.runtime.version_vector };
  assert.equal(mayCarry(manifest, candidate), true);
  assert.equal(mayCarry(manifest, { ...candidate, fingerprint: { ...candidate.fingerprint, file_count: 43 } }), false);
});

test("deploy uses only successful CI releases and preserves controlled acceptance fencing", () => {
  const deploy = readFileSync(new URL("../workflows/deploy.yml", import.meta.url), "utf8");
  assert.match(deploy, /workflows: \[CI\]/);
  assert.doesNotMatch(deploy, /SOURCE_WORKFLOW|verify-nightly|FULL_NIGHTLY_EVIDENCE_READY/);
  assert.match(deploy, /acceptance-begin/);
  assert.match(deploy, /--adopt-historical-pause/);
  assert.match(deploy, /acceptance-complete/);
  assert.match(deploy, /expected-generation/);
  assert.match(deploy, /A08_SCOPED_READ_ONLY_ACCEPTANCE/);
  assert.match(deploy, /seo:council-notification-acceptance/);
  assert.match(deploy, /seo:council-acceptance-readback/);
  assert.match(deploy, /notification_configuration_verified:true/);
  assert.match(deploy, /receipt_to_ui_verified:true/);
  assert.match(deploy, /natural_slot_receipt:false/);
  assert.match(deploy, /business_write_enabled == false/);
});
