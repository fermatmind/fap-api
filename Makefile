.PHONY: selfcheck selfcheck-mysql
# 用法：
#   make selfcheck
#   make selfcheck MANIFEST=../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.2/manifest.json

selfcheck:
	cd backend && ./scripts/selfcheck.sh "$(MANIFEST)"

# 用法：
#   make selfcheck-mysql
# 说明：
#   需先准备本地 MySQL（127.0.0.1:3306, root/root, database=fap_ci）
selfcheck-mysql:
	cd backend && \
	export APP_ENV="$${APP_ENV:-ci}" && \
	export DB_CONNECTION="$${DB_CONNECTION:-mysql}" && \
	export DB_HOST="$${DB_HOST:-127.0.0.1}" && \
	export DB_PORT="$${DB_PORT:-3306}" && \
	export DB_DATABASE="$${DB_DATABASE:-fap_ci}" && \
	export DB_USERNAME="$${DB_USERNAME:-root}" && \
	export DB_PASSWORD="$${DB_PASSWORD-root}" && \
	bash scripts/ci/prepare_mysql.sh && \
	APP_ENV=testing php artisan test && \
	APP_ENV=testing bash scripts/ci_smoke_v0_3.sh
