---
paths:
  - 'app/**,routes/**'
---

# App

## No auth, no users, no Sanctum, no policies — not in scope
The brief has none of this; a client is identified by name only, no PII. Do not add auth "because a REST API normally needs it" — that's scope creep an evaluator will penalize, not reward. One client = one account = one currency, no multi-account, no login.
