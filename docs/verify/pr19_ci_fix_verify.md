# PR19 CI 修复验证记录

## 失败原因（CI）
- workflow PHP 版本与依赖要求不匹配（需要 >=8.3）。
- MySQL 上迁移 UNIQUE/INDEX 重复创建导致 migrate 失败（幂等不足）。

## 修复点
- workflow PHP 升级到 8.4，补齐扩展（mbstring/sqlite/pdo_sqlite/mysql/pdo_mysql）。
- migrate 使用 `-vvv` 并新增二次 migrate 作为幂等门禁。
- 迁移 UNIQUE/INDEX 全部统一显式命名 + `indexExists` 判断（含 commerce v2 与 results 唯一约束）。

## 可复制验收命令
```bash
cd backend
composer install
php artisan migrate --force
php artisan db:seed --class=Pr19CommerceSeeder
php artisan test --filter=V0_3
bash scripts/pr19_verify_commerce_v2.sh
cd ..
PORT=18001 bash backend/scripts/ci_verify_mbti.sh
```
