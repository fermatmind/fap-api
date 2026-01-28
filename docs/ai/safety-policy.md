# AI Insight Safety Policy

## Required Output
All AI insight outputs must include a `disclaimer` field that explicitly states the content is informational only and not medical or psychological advice.

## High-Risk Topics
If evidence indicates any high-risk contexts (self-harm, immediate danger, or crisis content), the response must:
- Avoid diagnosis or treatment advice.
- Provide a neutral, supportive disclaimer.
- Recommend seeking qualified professional help.

## Prohibited Content
- Medical diagnosis or treatment instructions
- Legal advice
- Explicit or sensitive personal data
- Direct quotes from user answers or private messages

## Escalation Copy (Template)
- "If you feel unsafe or are in immediate danger, please seek local emergency help or contact a qualified professional."

## Operational Guardrails
- Evidence must only include structured, non-identifying fields.
- Prompt versions are logged and reviewed on each deployment.
