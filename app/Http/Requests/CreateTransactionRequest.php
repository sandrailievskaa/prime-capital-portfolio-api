<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * Single-endpoint design (ADR recommendation): POST /clients/{client}/transactions
 * with `type` in the body selecting behavior. One FormRequest, not four —
 * Laravel resolves a FormRequest by its type-hint before the controller
 * method runs, so a single action can't dynamically pick between four
 * per-type request classes without hand-rolled resolution logic that adds
 * complexity for no benefit. required_if/prohibited_if branch on `type`
 * instead, mirroring the single-table-with-a-type-column shape already
 * chosen for the schema itself (ADR-003).
 *
 * Structural validation only — this does not check real data (cash balance,
 * held quantity). instrument_id existence is the one exception: it's a
 * referential-integrity check (Laravel's `exists` rule), not a business
 * rule in this codebase's sense (rules 8/9, which require reasoning about a
 * computed ledger aggregate) — CLAUDE.md rule 2 treats "does this
 * instrument exist" as a validation-time concern, and it's explicitly
 * listed alongside the structural checks for this phase.
 */
class CreateTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // no auth, no policies — rule 13
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(TransactionType::class)],

            // amount/price MUST arrive as JSON strings, not bare numbers.
            // A JSON number literal 10.50 is indistinguishable from 10.5 by
            // the time PHP's json_decode produces a float — the trailing
            // zero that makes "exactly 2 decimal places" a meaningful
            // statement simply doesn't survive float representation. Only
            // a quoted string preserves it, which is also exactly what
            // brick/money expects to construct a Money value from (ADR-002).
            'amount' => [
                'bail',
                'required_if:type,deposit,withdrawal',
                'prohibited_if:type,buy,sell',
                'string',
                'numeric',
                'decimal:2',
                // Second layer, same defense-in-depth pattern as the ADR-003
                // DB CHECK: decimal:2 alone accepts "+10.50" (leading sign)
                // and ".50" (no leading digit) — both numerically valid but
                // not canonical. This regex pins the exact shape: one or
                // more leading digits, a literal dot, exactly two digits.
                // Leading zeros ("010.50") are deliberately left alone —
                // numerically unambiguous, not worth constraining further.
                'regex:/^\d+\.\d{2}$/',
                'gt:0',
            ],

            'instrument_id' => [
                'bail',
                'required_if:type,buy,sell',
                'prohibited_if:type,deposit,withdrawal',
                'integer',
                'exists:instruments,id',
            ],

            'quantity' => [
                'bail',
                'required_if:type,buy,sell',
                'prohibited_if:type,deposit,withdrawal',
                'integer',
                'min:1',
            ],

            'price' => [
                'bail',
                'required_if:type,buy,sell',
                'prohibited_if:type,deposit,withdrawal',
                'string',
                'numeric',
                'decimal:2',
                'regex:/^\d+\.\d{2}$/',
                'gt:0',
            ],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'amount.string' => 'The amount field must be a JSON string (e.g. "10.50"), not a number — this is the only way to reliably preserve exactly 2 decimal places.',
            'price.string' => 'The price field must be a JSON string (e.g. "10.50"), not a number — this is the only way to reliably preserve exactly 2 decimal places.',
            'amount.decimal' => 'The amount field must have exactly 2 decimal places.',
            'price.decimal' => 'The price field must have exactly 2 decimal places.',
            'amount.regex' => 'The amount field must be a plain decimal string like "10.50" — no leading + sign, and a leading digit is required (use "0.50", not ".50").',
            'price.regex' => 'The price field must be a plain decimal string like "10.50" — no leading + sign, and a leading digit is required (use "0.50", not ".50").',
            'instrument_id.exists' => 'The selected instrument does not exist.',
        ];
    }

    /**
     * Reject any field not in the known shape for this endpoint — no silent
     * pass-through of unexpected keys.
     */
    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            // Derived from rules(), not a second hand-maintained list — a
            // field added to rules() later is automatically allowed here
            // too, with no risk of the two lists drifting apart.
            $allowed = array_keys($this->rules());
            $unknown = array_diff(array_keys($this->all()), $allowed);

            foreach ($unknown as $field) {
                $validator->errors()->add($field, 'Unknown field.');
            }
        });
    }

    /**
     * Machine-readable error-code envelope, per CLAUDE.md's confirmed
     * API-surface note. `unknown_instrument` is emitted specifically when
     * instrument_id fails the `exists` check; everything else structural
     * shares a generic code — insufficient_funds/insufficient_holdings are
     * business-rule codes that belong to the service layer (Phase 4), not
     * this FormRequest.
     */
    protected function failedValidation(ValidatorContract $validator): void
    {
        $failed = $validator->failed();

        $errorCode = isset($failed['instrument_id']['Exists'])
            ? 'unknown_instrument'
            : 'validation_failed';

        throw new HttpResponseException(response()->json([
            'error_code' => $errorCode,
            'message' => $validator->errors()->first(),
            'errors' => $validator->errors(),
        ], 422));
    }
}
