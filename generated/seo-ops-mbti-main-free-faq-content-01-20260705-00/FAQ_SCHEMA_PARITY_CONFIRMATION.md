# FAQ_SCHEMA_PARITY_CONFIRMATION

Conclusion: PASS.

The backend change only updates the scale lookup authority field:
- `content_i18n_json.zh.faq`

Parity rationale:
- The visible FAQ content is returned by the public scale lookup API from `content_i18n_json.zh.faq`.
- The existing frontend/schema parity contract remains valid because visible FAQ and FAQPage JSON-LD derive from the same backend FAQ authority path.
- This PR does not change schema rendering policy, JSON-LD generation policy, or frontend FAQ fallback behavior.

Risk controls:
- Focused backend test asserts the exact 8 visible FAQ questions in the public lookup payload.
- Forbidden phrases and high-risk claims are explicitly asserted absent.
- No fap-web file was touched.

