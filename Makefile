.PHONY: selfcheck
# 用法：
#   make selfcheck
#   make selfcheck MANIFEST=../content_packages/default/CN_MAINLAND/zh-CN/MBTI-CN-v0.2.1-TEST/manifest.json

selfcheck:
	cd backend && ./scripts/selfcheck.sh "$(MANIFEST)"
