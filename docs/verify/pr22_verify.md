# PR22 Verify

## Commands
```bash
export CI=true
export FAP_NONINTERACTIVE=1
export COMPOSER_NO_INTERACTION=1
export GIT_TERMINAL_PROMPT=0
export NO_COLOR=1

bash backend/scripts/pr22_accept.sh
bash backend/scripts/ci_verify_mbti.sh
```

## Expected outputs
- Artifacts in `backend/artifacts/pr22/`
  - `boot_cn.json`, `boot_us.json`
  - `headers_cn.txt`, `headers_us.txt`
  - `verify.log`, `server.log`, `summary.txt`
- Boot responses include `Cache-Control`, `Vary`, `ETag` headers
- `If-None-Match` returns `304` with same cache headers
- IQ_RAVEN assets URLs start with `config('cdn_map.map.US.assets_base_url')` when `X-Region=US`
