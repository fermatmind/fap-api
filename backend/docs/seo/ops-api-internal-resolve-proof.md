# OPS-API-INTERNAL-RESOLVE-PROOF Report

## 1. Executive Summary

This read-only proof verified that the current Node1 / fap-web API access path to `api.fermatmind.com` is stable for the Foundation Daily Giving public API endpoint.

The current working path is DNS/current edge based:

- Node1 resolves `api.fermatmind.com` to `49.235.131.248`.
- Node1 curl direct HTTPS returned `200` in 5/5 attempts.
- Node1 Node.js HTTPS returned `200` in 5/5 attempts.
- Node1 apex same-origin API returned `200` in 3/3 attempts.
- Node2 direct HTTPS returned `200` in 5/5 attempts.
- API host direct HTTPS returned `200` in 3/3 attempts.
- Successful checks returned the same 92-byte body hash: `a2ed867b5370d429815f17789722880516b11c9627aecf9e4454f4f163a4dbd2`.

The older forced resolve path `api.fermatmind.com:443:198.18.14.180` is not stable: Node1 timed out in 3/3 attempts. It should not be treated as the current production fap-web API path unless a future OPS task explicitly repairs and proves it.

Final decision: `ops_api_internal_resolve_proof_completed_with_sidecars`.

## 2. Scope And Safety

This task was read-only. It used public HTTP(S) checks and read-only SSH command execution only.

No production data, CMS content, Search Channel queue, URL submission, external search API, env, DNS, nginx, certificate, security group, service, deploy, or raw log state was mutated.

## 3. Local Direct API Check

Local curl to:

`https://api.fermatmind.com/api/v0.5/foundation/giving-records?locale=en`

Result:

- HTTP status: `200`
- remote IP: `198.18.0.106`
- body size: `92`
- body SHA-256: `a2ed867b5370d429815f17789722880516b11c9627aecf9e4454f4f163a4dbd2`

## 4. Node1 Direct DNS / HTTPS Proof

Node1 host:

- SSH alias: `fap-web-node1`
- hostname: `VM-4-7-ubuntu`
- role: production fap-web Node1

Node1 DNS observation:

- `49.235.131.248 api.fermatmind.com`

Node1 curl direct HTTPS:

| Attempt | Status | Remote IP | Total Time | Bytes |
| --- | --- | --- | --- | --- |
| 1 | 200 | 49.235.131.248 | 0.174924s | 92 |
| 2 | 200 | 49.235.131.248 | 0.173686s | 92 |
| 3 | 200 | 49.235.131.248 | 0.260438s | 92 |
| 4 | 200 | 49.235.131.248 | 0.172780s | 92 |
| 5 | 200 | 49.235.131.248 | 0.178776s | 92 |

All five attempts returned the same body hash.

## 5. Node1 Node.js Runtime Proof

Node1 Node.js HTTPS check to the same API URL returned:

| Attempt | Status | Time | Bytes |
| --- | --- | --- | --- |
| 1 | 200 | 192ms | 92 |
| 2 | 200 | 150ms | 92 |
| 3 | 200 | 181ms | 92 |
| 4 | 200 | 170ms | 92 |
| 5 | 200 | 256ms | 92 |

All five attempts returned body hash `a2ed867b5370d429815f17789722880516b11c9627aecf9e4454f4f163a4dbd2`.

This is the most relevant proof for fap-web server-side runtime behavior because it exercises Node.js HTTPS rather than only curl.

## 6. Apex Same-Origin API Proof

Node1 curl to:

`https://fermatmind.com/api/v0.5/foundation/giving-records?locale=en`

Result:

| Attempt | Status | Remote IP | Total Time | Bytes |
| --- | --- | --- | --- | --- |
| 1 | 200 | 49.235.131.248 | 0.284091s | 92 |
| 2 | 200 | 49.235.131.248 | 0.237751s | 92 |
| 3 | 200 | 49.235.131.248 | 0.263454s | 92 |

All three attempts returned the same body hash.

## 7. Node2 And API Host Proof

Node2 host:

- SSH alias: `fap-node2`
- hostname: `VM-4-14-ubuntu`

Node2 direct HTTPS returned `200` in 5/5 attempts, remote IP `49.235.131.248`, body size `92`, and the same body hash across all attempts.

API host:

- SSH alias: `fap-api-prod`
- hostname: `iZuf644iq7ee8g87y6pi7wZ`

API host direct HTTPS returned `200` in 3/3 attempts, remote IP `49.235.131.248`, body size `92`, and the same body hash across all attempts.

## 8. Forced Resolve Sidecar

Node1 forced resolve to the older path:

`api.fermatmind.com:443:198.18.14.180`

Result:

- attempts: `3`
- successes: `0`
- curl statuses: `000`, `000`, `000`
- failure mode: connection timeout after 5 seconds

This is a sidecar, not a current runtime blocker, because Node1 direct DNS, Node1 Node.js HTTPS, Node1 apex same-origin API, Node2, and API host checks are all stable through the current route.

## 9. PM2 Sidecar

Read-only PM2 observation on Node1:

- `fap-web` instances: `4`
- status: all `online`
- `unstable_restarts`: `0` for all instances
- `restart_time`: `188` for all instances

The high restart counter remains an operational sidecar. No PM2 restart or service mutation was performed.

## 10. What Was Not Done

- No production mutation.
- No deploy.
- No CMS mutation.
- No Search Channel action.
- No URL submission.
- No external search API call.
- No env, DNS, nginx, certificate, or security group edit.
- No service restart.
- No raw log access.

## 11. Final Decision

`ops_api_internal_resolve_proof_completed_with_sidecars`

## 12. Next Task

`LEGACY-SEO-RECONCILIATION-SCAN`
