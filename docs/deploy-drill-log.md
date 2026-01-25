
## 2026-01-25 rollback drill
- Performed auto-rollback drill via forced healthcheck failure
- Verified: deploy:failed -> rollback -> reload php-fpm/nginx
- Verified: questions + attempts/start OK after restore deploy
