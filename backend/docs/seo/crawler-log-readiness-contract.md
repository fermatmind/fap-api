# Crawler Log Readiness Contract

## Purpose

CRAWLER-LOG-00 defines the source approval, sanitization, and ingestion readiness boundary for future crawler log intelligence.
This PR does not read production crawler logs, change CDN/Nginx/OpenResty configuration, add credentials, edit environment files, enable scheduler, run collector writes, deploy services, or modify runtime behavior.

## Source Decision Boundary

Future crawler log work must explicitly approve one source family before any read occurs:

- CDN access logs
- Nginx access logs
- OpenResty access logs

The approved source must document owner, path or export mechanism, retention, masking posture, access control, and rollback owner.
Until that approval exists, production crawler log reads remain blocked.

## Sanitization Rules

Crawler log ingestion may store aggregate-only rows after sanitization.

Allowed stored fields:

- report date
- bot family
- source engine
- route family
- locale
- page entity type
- status code bucket
- response time bucket
- crawl count
- private-flow count
- noindex count

Forbidden stored fields:

- raw IP
- cookie or raw cookie
- raw user agent
- full raw URL with query string
- raw payload
- token, API key, or secret
- raw email, order number, attempt ID, payment ID, or provider event ID

Raw user agent strings may be used transiently for classification only and must not be stored.
Raw IPs must not be stored; if a future classifier needs network-level grouping it must use an approved irreversible hash or aggregate bucket.

## Bot Classifier Scope

Allowed bot families:

- googlebot
- bingbot
- baiduspider
- bytespider
- sogou
- so360
- shenma
- yandex
- other_bot
- unknown

Chinese crawler labels must remain classifier labels only. They must not trigger URL submissions, alternate page generation, or search-channel actions.

## Approval Gates

Before a future production crawler-log read or ingestion:

- source owner approves source path/export
- security owner approves masking and access control
- backend owner approves sanitized schema
- SEO Intelligence owner approves report-only purpose
- production operation receives explicit human approval
- dry-run output confirms no raw IP, cookie, raw user agent, query string, raw payload, or PII
- scheduler remains disabled unless separately approved later

## Stop Conditions

Stop immediately if:

- production crawler logs are read in a docs/spec PR
- raw IP, cookie, raw user agent, or raw URL query strings are stored
- CDN/Nginx/OpenResty config is changed
- scheduler or queue workers are enabled
- crawler observations trigger URL submissions
- crawler logs become URL Truth authority
- Node2 local DB or business raw tables are used

## Next Task

Next task: CLAIM-LINT-00.
