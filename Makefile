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
	export DB_PORT="$${DB_PORT:-33306}" && \
	export DB_DATABASE="$${DB_DATABASE:-fap_ci}" && \
	export DB_USERNAME="$${DB_USERNAME:-root}" && \
	export DB_PASSWORD="$${DB_PASSWORD:-root}" && \
	export MYSQL_BOOTSTRAP="$${MYSQL_BOOTSTRAP:-1}" && \
	if [ "$$MYSQL_BOOTSTRAP" = "1" ]; then bash scripts/ci/ensure_mysql.sh; fi && \
	bash scripts/ci/prepare_mysql.sh && \
	APP_ENV=testing php vendor/phpunit/phpunit/phpunit --configuration phpunit.mysql.xml && \
	APP_ENV=testing bash scripts/ci_smoke_v0_3.sh

.PHONY: release release\:source-clean release\:verify

release\:source-clean:
	bash scripts/release/export_source_clean.sh

release\:verify:
	bash scripts/release/verify_source_zip_clean.sh dist/source_clean.zip

release:
	@$(MAKE) release:source-clean
	@$(MAKE) release:verify
	@echo "[release] artifact=dist/source_clean.zip"
	@echo "[release] commit_sha=$$(git rev-parse --short=12 HEAD)"
	@echo "[release] generated_at_utc=$$(date -u +%Y-%m-%dT%H:%M:%SZ)"
	@echo "[release] build_host=$$(uname -srm)"
