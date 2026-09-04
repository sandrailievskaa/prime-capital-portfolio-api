---
paths:
  - app/Http/Requests/StoreTransactionRequest.php
---

# Requests

## amount/price must be JSON strings, not bare numbers
Client must send "amount": "10.50" (quoted string), never a bare JSON number — a bare 10.50 is indistinguishable from 10.5 once PHP parses it, so decimal:2 can't reliably enforce "exactly 2 decimal places" against it. Validation is 'string','numeric','decimal:2', plus regex /^\d+\.\d{2}$/ (rejects leading + sign and no-leading-digit ".50"). Whitespace like " 10.50 " is normalized to "10.50" by Laravel's global TrimStrings middleware before this FormRequest runs — testing the validator in isolation (bypassing HTTP) makes whitespace look like it fails; it doesn't through the real request pipeline.
