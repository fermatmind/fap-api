# PR14 Verify

## Commands
```bash
cd backend && composer install && composer audit
cd backend && php artisan migrate
cd backend && PORT=18040 bash scripts/pr14_verify_agent_memory.sh
cd .. && PORT=18041 bash backend/scripts/ci_verify_mbti.sh
```

## Expected Outputs
- backend/artifacts/pr14/summary.txt
  - memory_id
  - agent_message_id
  - trigger_type
  - budget_degraded=false/true
- backend/artifacts/pr14/logs/server.log
  - contains /memory and /me/agent requests
