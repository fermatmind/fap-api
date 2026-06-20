# Question API compression runbook

## Purpose

The public question endpoints can return large JSON payloads, especially IQ SVG-backed packs. Production API hosts must compress JSON responses before traffic leaves Nginx or the upstream gateway.

## Nginx baseline

Add or verify these directives in the API server or gateway `http` block. If Alibaba Cloud or Tencent Cloud terminates compression before Nginx, apply the equivalent gateway setting there instead.

```nginx
gzip on;
gzip_vary on;
gzip_comp_level 5;
gzip_min_length 1024;
gzip_proxied any;
gzip_types
    application/json
    application/problem+json
    application/javascript
    text/css
    text/plain
    text/xml
    application/xml
    image/svg+xml;
```

Brotli can be enabled instead of, or in addition to, gzip when the installed Nginx build supports it:

```nginx
brotli on;
brotli_comp_level 5;
brotli_min_length 1024;
brotli_types
    application/json
    application/problem+json
    application/javascript
    text/css
    text/plain
    text/xml
    application/xml
    image/svg+xml;
```

## Verification

Run after config reload and before deploy closeout:

```bash
curl --compressed -sS -D /tmp/fap-riasec-headers.txt -o /tmp/fap-riasec.json \
  'https://api.fermatmind.com/api/v0.3/scales/RIASEC/questions?locale=zh-CN&form_code=riasec_60'
rg -n '^(content-encoding|cache-control|content-length):' /tmp/fap-riasec-headers.txt

curl --compressed -sS -D /tmp/fap-iq-headers.txt -o /tmp/fap-iq.json \
  'https://api.fermatmind.com/api/v0.3/scales/IQ_RAVEN/questions?locale=zh'
rg -n '^(content-encoding|cache-control|content-length):' /tmp/fap-iq-headers.txt

curl --compressed -sS -o /dev/null -w 'ttfb=%{time_starttransfer} total=%{time_total} bytes=%{size_download}\n' \
  'https://api.fermatmind.com/api/v0.3/scales/IQ_RAVEN/questions?locale=zh'
```

Expected headers:

- `content-encoding: gzip` or `content-encoding: br`
- `cache-control: public, max-age=300, stale-while-revalidate=600`
- Warm question endpoint TTFB below 300ms from the API host or Node1 network path
