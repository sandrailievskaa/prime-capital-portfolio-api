---
paths:
  - 'app/Http/Controllers/**,app/Exceptions/**,bootstrap/app.php,app/Http/Requests/**'
---

# Http Requests

## Error envelope: error_code+message always; errors only on FormRequest 422s
Every error response carries {error_code, message}. The errors key (Laravel's per-field MessageBag) is present ONLY on 422s from FormRequest field validation (StoreTransactionRequest, StoreInstrumentRequest — each implements its own failedValidation() with this same shape) — business-rule rejections (insufficient_funds, insufficient_holdings) and 404 not_found have no errors key, deliberately: a business-rule rejection is a whole-request decision with no single field to blame. DomainRuleException subclasses are handled by one render() callback in bootstrap/app.php keyed off the abstract base class — a new subclass needs zero changes there, just extend DomainRuleException and implement errorCode().

## Form Request naming: Store{Model}Request / Update{Model}Request, matching the resource action
Not Create{Model}Request — Laravel's own docs name the equivalent example StorePostRequest. StoreTransactionRequest and StoreInstrumentRequest back the store() action on their respective apiResource routes.
