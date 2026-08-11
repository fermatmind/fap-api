# BACKEND-DEPLOY-TARGET-ALIYUN-01

Status: current runtime target; operational closeout has explicit tails
Owner: backend deploy / SRE
Last updated: 2026-08-11

## Decision

FermatMind 的 production 与 staging 运行时已经从腾讯云收敛为阿里云三节点。腾讯云不再承载 Web、API、queue、scheduler、MySQL 或 Redis 的生产运行依赖。

腾讯侧仍可保留域名注册、DNSPod 和 ICP 管理。这些是外部控制面，不等于腾讯 runtime 仍在服役。

所有主机 IP、SSH 用户、路径、RDS/Redis endpoint 和密钥只允许存在于受保护 Environment、部署配置或批准的运维 inventory；仓库文档保持 redacted。

## Current Three-node Topology

| Role | Runtime | Data / process boundary | Public entry |
|---|---|---|---|
| Production Web | Next.js + Node.js + PM2；OpenResty public ingress | 2 个 Web processes；不拥有 CMS authority | root、`/en`、`/zh`、测试与结果产品路由 |
| Production API / Ops | Laravel 12 + Nginx + PHP-FPM | Supervisor queues、scheduler、本机 Redis、Alibaba RDS MySQL | `api.fermatmind.com`、`ops.fermatmind.com` |
| Staging combined | Web + API + Nginx + PHP-FPM + Redis | 明确 no-worker queue topology | staging Web 与 staging API hosts |

Production API 的运行约束：

- MySQL authority 位于 Alibaba RDS；
- Redis 为 API 节点本机受管实例，当前 approved connection 是 loopback 上的非默认端口；
- production queue 由 Supervisor 管理，deploy 必须验证 required programs 并执行 manager reload；
- scheduler 指向 managed current release；
- staging 没有受管或非受管 Laravel queue worker，deploy 因此允许 `queue_reload_required=false`，但会 fail closed 检查意外 worker。

详细变量和 host defaults 以 `deploy.php` 与 protected GitHub Environments 为准，不以本文档复制值为准。

## Authority and Storage

- backend/CMS/public API 是文章、landing、personality、Career、metadata、sitemap 和 llms enumeration authority。
- managed deploy 结构保持 `releases`、`current` symlink、shared env、shared storage 与 shared content packages。
- production backend `keep_releases=5`；staging `keep_releases=3`。
- release activation 前会删除 allowlisted 的非 runtime 目录，避免 `.agents`、`.github`、`.vscode`、`docs`、`tests` 与 `backend/tests` 长期占用发布盘。
- Greenfield baseline 是迁移/恢复包，不是 runtime page-rendering fallback。当前基线故意保留 342 tracked / 30 public Career projection，不能被解释为 1046 authority。

## Deployment Control Plane

Production deployment uses protected GitHub Actions + Deployer SSH and must bind:

- the exact approved immutable candidate SHA/release and its exact successful staging evidence;
- candidate reachability from current `main`, while explicitly excluding every newer `main` commit from the deployment;
- protected `production` Environment identity and secrets;
- candidate release `REVISION` matching the authorized SHA;
- candidate/private and public DNS evidence;
- queue manager capability;
- migration, cache, health and post-activation checks;
- rollback before any unsafe activation.

Staging uses the protected `staging` Environment and a separate host identity. Production and staging secrets must not fall back to repository Variables or deprecated deploy secret names.

The retired Tencent webhook and direct Node3 deployment path stay disabled. `PRODUCTION_RETIRED_DEPLOY_HOST` may remain only as a fail-closed comparison value; it is not a runtime dependency.

## Current Deployment Incident Boundary

At the 2026-08-11 evidence cutoff:

| Fact | State |
|---|---|
| active production backend revision | `40020ab7ef269ee56ce597e9f2fd2fbb99e83549` |
| failed immutable candidate revision | `ecc62a386076e29969d6975a2767f11963bb1690` |
| repository/control-plane `main` | `25cbc70f60d2a32901339e7e2469d4ddf196e173` |
| database migrations for candidate | executed during the failed activation window |
| candidate activation | blocked by public-DNS guard before symlink move |
| DNS guard repair | PR #3645 merged as `25cbc70f60d2a32901339e7e2469d4ddf196e173`；that control-plane SHA's CI/staging is running, with no new production dispatch at evidence cutoff |

Therefore production currently has a possible schema/code split. Do not infer active application behavior from repository `main`. After the merged guard repair's exact control-plane CI/staging evidence completes, a new protected deployment bound to the approved immutable candidate SHA/release and full post-activation readback are required; the previous failed deploy does not authorize an automatic retry. The deployment must not silently substitute newer `main` commits for the reviewed candidate.

See [FermatMind 窗口 1–10、IQ 与阿里云迁移完成度复核](../../backend/docs/seo/window-01-10-alibaba-migration-status-2026-08-11.md) for the downstream Career, Measurement and SEO implications.

## TLS and Restart Resilience

The 2026-08-10 migration receipts record:

- certificate automatic renewal ready across the three-node topology;
- service reload hooks and timers validated;
- three-node reboot and application self-recovery validated;
- no unversioned SSH config edit or automatic application rollback introduced.

Certificate paths and authenticator details are node-specific operational configuration. Future renewal changes must use the protected control path and fresh readback, not this historical receipt alone.

## SSH and Access Boundary

The migration closeout records the three Alibaba nodes with:

- non-root key-based operator access and sudo;
- root login disabled;
- password and keyboard-interactive SSH disabled;
- bounded authentication attempts and login grace time;
- local aliases limited to current production/staging/GitHub identities.

Password rotation remains a separate operational tail. Repository documentation must not store passwords, private keys, raw known-host content or public IP inventory.

## Tencent Retirement

The 2026-08-10 runtime receipts record:

- `TENCENT_RUNTIME_RESOURCES_ZERO`;
- old Tencent/HK runtime aliases removed from the local operator config;
- production DNS no longer points at the retired runtime;
- no production Web/API/queue/scheduler/DB/Redis dependency on Tencent.

The previous 2026-05 statement that “Tencent DB/Redis remain dependencies” is retired. Node2/Node3 historical instructions are audit history only and must not be used as current deploy guidance.

## Data Services

- Production MySQL is Alibaba RDS. A backup/restore drill completed and its temporary restore instance was released.
- Runtime PostgreSQL is disabled. The remaining PostgreSQL RDS stores only Metabase metadata and is classified `ARCHIVE_AND_RETIRE`; it is not an application runtime dependency, but retirement is not complete.
- Production Redis is local to the API node. Staging Redis is local to the staging node.
- Never copy environment endpoints into CMS, frontend fallback code or public evidence.

## Release Storage and Logs

Code-level governance is verified:

- Production API releases: keep 5.
- Staging API releases: keep 3.
- Frontend production/staging retention is owned by the fap-web deployment contract.

The external Task 10 preflight planned production Web 3 releases/artifacts, production API 5 releases, staging 3 releases, journald 14-day retention and bounded 128M/256M limits. A parallel operator report says it was applied, but this document update did not perform SSH readback and no independent Task 10 apply receipt was found. Treat the server-side counts, journal cap and reclaimed disk as `OBSERVED_NOT_REVERIFIED` until a bounded read-only receipt is produced.

## Safe Operational Readback

Repository-level checks:

```bash
rg -n "keep_releases|queue_reload_required|PRODUCTION_RETIRED_DEPLOY_HOST" deploy.php .github/workflows
php -l deploy.php
git diff --check
```

Separately authorized, bounded environment readback should verify:

1. exact active `REVISION` and `current` symlink target;
2. migration status and no unexpected pending migration;
3. Nginx/PHP-FPM, Supervisor required workers and scheduler;
4. Redis and RDS connectivity without printing endpoints or credentials;
5. release counts, journal limits and filesystem headroom;
6. the public `GET /up` boot smoke returns HTTP `200`;
7. target-node loopback vhost `GET /api/healthz` returns HTTP `200` with JSON `ok=true`;
8. the same path from a non-allowlisted public origin remains exactly HTTP `404`—public healthz must never be required to return `200`;
9. the public flags API returns HTTP `200`;
10. `GET /api/v0.5/personality-content-assets/big_five/hub/big-five?locale=zh-CN` returns HTTP `200`, JSON `ok=true`, and a lowercase hexadecimal `personality_public_content_asset_v1.source_hash` of exactly 64 characters;
11. core assessment, Career directory/detail, sitemap source and ops entry remain healthy;
12. no 5xx/timeout appears in repeated intervals.

Health evidence must keep the public `/up` boot smoke, loopback/allowlisted internal health and public-DNS business evidence separate. The readback may retain sanitized booleans, status codes and opaque hashes, but must not print response bodies, topology or credentials.

SSH readback is diagnostic only. Production deploy, database/CMS write, cache warm, service restart, DNS change, secret rotation and resource deletion remain separate controlled actions.

## Remaining Closeout

1. Finish exact CI/staging evidence for the merged bounded public-DNS guard control plane, then execute a new production deployment bound to the exact approved immutable candidate SHA/release and staging receipt.
2. Reconcile active code, migrations and public surfaces after activation.
3. Produce an independent Task 10 release/journald/disk readback receipt.
4. Archive and retire the Metabase-only PostgreSQL RDS.
5. Complete password rotation.
6. Retire temporary Career/MBTI workflows after their project-specific closeouts.
