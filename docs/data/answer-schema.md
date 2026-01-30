# Answer Schema v0.3 (PR21)

Date: 2026-01-30

## 1) Scope
This document defines:
- v0.3 answer payload shape (generic question types).
- Storage schema for `attempt_answer_sets` / `attempt_answer_rows`.
- Canonicalization and hashing rules (`answers_hash`, `answers_digest`).

## 2) Question Types (content pack)
`questions.json` supports at least these generic types:

### 2.1 slider
```json
{
  "question_id": "DEMO-SLIDER-1",
  "type": "slider",
  "text": "...",
  "min": 1,
  "max": 5,
  "step": 1,
  "labels": {"min": "低", "max": "高"},
  "default": 3
}
```

### 2.2 rank_order
```json
{
  "question_id": "DEMO-RANK-1",
  "type": "rank_order",
  "text": "...",
  "options": [
    {"code": "A", "text": "..."},
    {"code": "B", "text": "..."},
    {"code": "C", "text": "..."}
  ],
  "max_rank": 3,
  "assets": {"image": "assets/images/demo_rank_1.png"}
}
```

### 2.3 open_text
```json
{
  "question_id": "DEMO-TEXT-1",
  "type": "open_text",
  "text": "...",
  "placeholder": "..."
}
```

## 3) Answer Payload Shape (API / progress / submit)
Each answer item is an object:
```json
{
  "question_id": "DEMO-SLIDER-1",
  "question_type": "slider",
  "question_index": 0,
  "code": "4",
  "answer": {"value": 4}
}
```

Notes:
- `question_id` is required.
- `question_type` is recommended to help auditing.
- `question_index` is optional (0-based).
- `code` is required for scoring (drivers still use code maps).
- `answer` holds type-specific payloads:
  - slider: `{ "value": <number> }`
  - rank_order: `{ "order": ["A","B","C"] }` (code example: `A>B>C`)
  - open_text: `{ "text": "..." }` (code example: `TEXT`)

## 4) Storage
### 4.1 attempt_answer_sets (final, 1 row per attempt)
- `answers_json`: gzip+base64 of **canonical JSON**.
- `answers_hash`: sha256 of canonical JSON string.

Canonicalization rules:
- Normalize each answer to keys: `question_id`, `question_index`, `question_type`, `code`, `answer`.
- Sort answers by `question_id` (ascending).
- Recursively sort keys inside `answer` objects.

Compression:
- `answers_json = base64_encode(gzencode(canonical_json, 9))`.

### 4.2 attempt_answer_rows (optional per-question rows)
- `answer_json` stores raw answer object as JSON (no compression).
- Rows are upserted by `(attempt_id, question_id)` when `ANSWER_ROWS_WRITE_MODE=on`.

## 5) Digest Rules
### 5.1 answers_hash
```
answers_hash = sha256(canonical_json)
```

### 5.2 answers_digest (attempts table)
```
answers_digest = sha256(UPPER(scale_code) + "|" + pack_id + "|" + dir_version + "|" + canonical_json)
```

This binds answers to the content pack + scale, and is used for submit idempotency.
