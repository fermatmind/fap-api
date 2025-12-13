# FAP v0.2 · MBTI API Endpoints (Stage 2 Skeleton)

Purpose  
Define the **behavior** of the 3 core GET endpoints for Stage 2 skeleton.  
Goal: Without DB / complex logic, Mini Program can already call these APIs and get valid JSON.

Scope  
- CN Mainland-first (region = CN_MAINLAND, locale = zh-CN)  
- Only MBTI scale in Stage 2  
- Implementation: Laravel (fermat-fap project)

---

## 1. GET /api/v0.2/health

### 1.1 Behavior

- Return basic service status for monitoring & debugging.
- No auth required.
- Can be used by:
  - Ops (curl/Postman)
  - Uptime robot / health checker

### 1.2 Response (static example)

```json
{
  "ok": true,
  "data": {
    "status": "ok",
    "service": "Fermat Assessment Platform API",
    "version": "v0.2-skeleton"
  },
  "meta": {
    "request_id": "req_xxx",
    "ts": 1730000000,
    "region": "CN_MAINLAND",
    "locale": "zh-CN",
    "version": "v0.2"
  }
}