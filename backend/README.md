<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

# FAP Backend (MBTI) · Verify / Acceptance (E2E)

本仓库是 FAP（Fermat Assessment Platform）后端（Laravel）。下面这段是**必须长期维护的“可重复验收入口”**：你在本机、服务器、CI 都应该能用同一套命令稳定验证“内容包 / 规则 / overrides”。

---

## 快速验收入口：verify_mbti（本机 / 服务器 / CI）

## 上线前置校验（必须）

```bash
cd /path/to/fap-api/backend
php artisan migrate --force
php artisan fap:schema:verify
```

两步缺一不可：先迁移，再做 schema 基线校验。

### 0) 你需要先有一个可访问的 API 服务

- 本机开发一般是：`http://127.0.0.1:8000`
- 服务器/CI：确保能访问到对应域名/端口

脚本默认用环境变量 `API`（默认 `http://127.0.0.1:8000`）。

---

## A. 本机模式（自动创建 attempt → 验收）

在 **backend/** 目录下执行：

```bash
cd /path/to/fap-api/backend
bash ./scripts/verify_mbti.sh
echo "EXIT=$?"
```

你会得到 artifacts：

- `backend/artifacts/verify_mbti/report.json`
- `backend/artifacts/verify_mbti/share.json`
- `backend/artifacts/verify_mbti/attempt_id.txt`
- `backend/artifacts/verify_mbti/logs/overrides_accept_D.log`

成功标准：

- 最后一行看到：`[DONE] verify_mbti OK ✅`
- `EXIT=0`

---

## B. 服务器 / CI 模式（复用已有 attempt → 只做验收）

当你需要在服务器/CI 重复验收**同一次 attempt**（避免每次都创建新 attempt），用：

```bash
cd /path/to/fap-api/backend
ATTEMPT_ID="<attempt_uuid>" bash ./scripts/verify_mbti.sh
echo "EXIT=$?"
```

### ATTEMPT_ID 从哪来？

最常用的来源就是你上一次跑 `verify_mbti` 生成的文件：

```bash
cd /path/to/fap-api/backend
ATTEMPT_ID="$(cat artifacts/verify_mbti/attempt_id.txt)"
ATTEMPT_ID="$ATTEMPT_ID" bash ./scripts/verify_mbti.sh
echo "EXIT=$?"
```

> 说明：复用 attempt 会走脚本里的 `[4/8] reuse attempt: ...`，只做验收，不重复创建。

---

## C. 指定 API 地址（服务器/CI 常用）

```bash
cd /path/to/fap-api/backend

# 直接创建 attempt 并验收
API="https://your-api.example.com" bash ./scripts/verify_mbti.sh

# 复用已有 attempt 验收
API="https://your-api.example.com" ATTEMPT_ID="<attempt_uuid>" bash ./scripts/verify_mbti.sh
```

---

## D. 关键环境变量（防止掉回 GLOBAL/en）

verify_mbti 默认用：

- `REGION=CN_MAINLAND`
- `LOCALE=zh-CN`
- `EXPECT_PACK_PREFIX=MBTI.cn-mainland.zh-CN.`

你也可以显式覆盖：

```bash
REGION="CN_MAINLAND" LOCALE="zh-CN" EXPECT_PACK_PREFIX="MBTI.cn-mainland.zh-CN." bash ./scripts/verify_mbti.sh
```

另外，建议在 CI/服务器环境里同时设置（对应 `config/content_packs.php` 的默认约束）：

- `FAP_DEFAULT_REGION=CN_MAINLAND`
- `FAP_DEFAULT_LOCALE=zh-CN`
- `FAP_DEFAULT_PACK_ID=MBTI.cn-mainland.zh-CN.v0.2.1-TEST`

---

## E. Overrides 回归验收（D-1/D-2/D-3）

你也可以单独跑 overrides 的回归：

```bash
cd /path/to/fap-api/backend
ATT="$(cat artifacts/verify_mbti/attempt_id.txt)"
bash ./scripts/accept_overrides_D.sh "$ATT"
echo "EXIT=$?"
```

成功标准：

- 最后一行必须是：`✅ ALL DONE: D-1 / D-2 / D-3 passed`
- 且 `EXIT=0`

---

## F. 本地 pre-commit 安全门禁（阻断 backend/.env）

在仓库根目录执行：

```bash
cd /Users/rainie/Desktop/GitHub/fap-api
chmod +x backend/scripts/pre_commit_env_guard.sh
cat > .git/hooks/pre-commit <<'HOOK'
#!/usr/bin/env bash
set -euo pipefail
bash backend/scripts/pre_commit_env_guard.sh
HOOK
chmod +x .git/hooks/pre-commit
```

手工验证：

```bash
cd /Users/rainie/Desktop/GitHub/fap-api
bash backend/scripts/pre_commit_env_guard.sh
```

说明：执行 `bash backend/scripts/ci_verify_mbti.sh` 或部分本地 artisan 命令后，可能生成 `backend/.env`；提交前执行 `rm -f backend/.env`。

---

## 常见问题

### 1) `Usage: ./scripts/accept_overrides_D.sh <ATTEMPT_ID>`
说明你没有传 attempt id，也没有导出 `ATT`/`ATTEMPT_ID`。
按上面方式从 `artifacts/verify_mbti/attempt_id.txt` 读取即可。

### 2) verify_mbti 失败了怎么定位？
看 artifacts：

- `artifacts/verify_mbti/logs/overrides_accept_D.log`
- `artifacts/verify_mbti/report.json` / `share.json`
- 失败时脚本会自动 tail 关键内容到 stderr

---

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework. You can also check out [Laravel Learn](https://laravel.com/learn), where you will be guided through building a modern Laravel application.

If you don't feel like reading, [Laracasts](https://laracasts.com) can help. Laracasts contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

## Laravel Sponsors

We would like to extend our thanks to the following sponsors for funding Laravel development. If you are interested in becoming a sponsor, please visit the [Laravel Partners program](https://partners.laravel.com).

### Premium Partners

- **[Vehikl](https://vehikl.com)**
- **[Tighten Co.](https://tighten.co)**
- **[Kirschbaum Development Group](https://kirschbaumdevelopment.com)**
- **[64 Robots](https://64robots.com)**
- **[Curotec](https://www.curotec.com/services/technologies/laravel)**
- **[DevSquad](https://devsquad.com/hire-laravel-developers)**
- **[Redberry](https://redberry.international/laravel-development)**
- **[Active Logic](https://activelogic.com)**

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).

## verify_mbti（E2E 验收脚本）

这套验收用来确保：
- 内容包（content pack）真的生效（不会 silent fallback 到 GLOBAL/en 或 deprecated）
- highlights 规则按 contract 运行（数量 / kind / 禁用项）
- overrides（D-1/D-2/D-3）命中与优先级可重复验证

产物默认落在：
- `backend/artifacts/verify_mbti/`
  - `report.json` / `share.json` / `summary.txt`
  - `logs/overrides_accept_D.log` 等

### 服务器 / Staging 运行方式（Runbook）

> 服务器通常已有 nginx + php-fpm 对外提供 API。此模式推荐用于「线上/预发」复验同一个 attempt。

#### 0) 前置条件
服务器需要具备：
- `bash`, `curl`, `jq`, `python3`
- PHP 必备扩展：`redis`（phpredis）
- 能访问 API（建议用内网域名或本机回环）
- 已部署正确的 content_packages（且默认 region/locale/pack 不会掉回 GLOBAL/en）

安装与验证（Ubuntu + ondrej/php）：

```bash
# 安装当前 PHP 小版本对应的 redis 扩展（示例：php8.4-redis）
sudo apt-get update
sudo apt-get install -y php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-redis

# 重启 php-fpm（示例：php8.4-fpm）
sudo systemctl restart php$(php -r 'echo PHP_MAJOR_VERSION.".".PHP_MINOR_VERSION;')-fpm

# 验证扩展已加载
php -m | grep -i '^redis$'
php --ri redis

#### 1) 必要参数：ATTEMPT_ID 从哪来？
有两种方式：

**方式 A（推荐，复验已有 attempt）：**
- 从日志/埋点/数据库/后台工具拿到某次真实请求的 `attempt_id`
- 然后用该 `ATTEMPT_ID` 复验（不会创建新 attempt）

**方式 B（在预发/测试环境造一个 attempt）：**
- 直接运行 `verify_mbti.sh`（不传 ATTEMPT_ID），脚本会自动：
  - 拉 questions
  - POST `/api/v0.2/attempts` 创建 attempt
  - 再拉 report/share 并做断言
- 创建出的 attempt id 会写入：`backend/artifacts/verify_mbti/attempt_id.txt`

> 注意：**生产环境不建议用方式 B**（会写库）。生产建议只用方式 A 复验已有 attempt。

#### 2) 一键复验（已有 attempt，推荐）
在服务器上进入 `backend/` 目录：

```bash
cd /var/www/fap-api/current/backend

# 关键：指定 API（你的线上/预发地址），并传入要复验的 attempt id
API="https://api.xxx.com" ATTEMPT_ID="xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx" \
  RUN_DIR="/tmp/verify_mbti_$(date +%Y%m%d_%H%M%S)" \
  bash ./scripts/verify_mbti.sh

echo "EXIT=$?"
