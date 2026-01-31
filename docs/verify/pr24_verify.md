# PR24 Verify

## Commands
```bash
export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

bash backend/scripts/pr24_accept.sh
bash backend/scripts/ci_verify_mbti.sh
```

## Expected outputs
- Artifacts in `backend/artifacts/pr24/`
  - `sitemap.xml`, `headers_200.txt`, `headers_304.txt`
  - `server.log`, `summary.txt`, `server.pid`
- `/sitemap.xml` returns XML with loc/lastmod/changefreq/priority
- Response headers include Cache-Control + ETag, and no Set-Cookie
- If-None-Match returns 304 with matching ETag

## Risk controls
- Sitemap data source is `scales_registry` (is_active=1) only
- Cache headers are strong (public + s-maxage + stale-while-revalidate)
- Route bypasses cookie/session middleware to keep CDN caching intact
- Artifacts are sanitized via `sanitize_artifacts.sh`
