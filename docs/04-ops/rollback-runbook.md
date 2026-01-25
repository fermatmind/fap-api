cat > docs/04-ops/rollback-runbook.md <<'EOF'
# Phase0 回滚演练 Runbook（生产）

目标：1 分钟内把 current 从最新 release 切回上一版，并验证 questions + attempts/start 正常，然后切回最新。

## 1. 查看当前版本
在服务器执行：

readlink -f /var/www/fap-api/current
ls -1 /var/www/fap-api/releases | tail -n 10

## 2. 回滚到指定 release（例：34）
cd /var/www/fap-api
echo "BEFORE:"
readlink -f current

ln -nfs /var/www/fap-api/releases/34 /var/www/fap-api/current

sudo -n /usr/bin/systemctl reload php8.4-fpm
sudo -n /usr/bin/systemctl reload nginx

echo "AFTER ROLLBACK:"
readlink -f current

## 3. 验收（必须全绿）
HOST=fermatmind.com

curl -fsS --resolve ${HOST}:443:127.0.0.1 \
  https://${HOST}/api/v0.2/scales/MBTI/questions >/dev/null && echo "questions OK"

curl -fsS --resolve ${HOST}:443:127.0.0.1 -X POST \
  https://${HOST}/api/v0.2/attempts/start \
  -H "Content-Type: application/json" -H "Accept: application/json" \
  -d '{"anon_id":"rb-001","scale_code":"MBTI","scale_version":"v0.2","question_count":144,"client_platform":"web","region":"CN_MAINLAND","locale":"zh-CN"}' \
  >/dev/null && echo "attempts/start OK"

## 4. 切回最新 release（例：35）
ln -nfs /var/www/fap-api/releases/35 /var/www/fap-api/current
echo "BACK TO LATEST:"
readlink -f current
EOF