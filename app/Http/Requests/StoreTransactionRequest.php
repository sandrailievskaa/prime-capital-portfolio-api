<?php

namespace App\Http\Requests;

use App\Enums\TransactionType;
use Illuminate\Contracts\Validation\Validator as ValidatorContract;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Validation\Rule;

/**
 * One FormRequest for all four transaction types — required_if/prohibited_if
 * branch on `type` rather than four per-type request classes, since Laravel
 * resolves a FormRequest by type-hint before the controller method runs.
 * Structural validation only; business rules (insufficient_funds/holdings)
 * are enforced in TransactionController against the live ledger.
 */
class StoreTransactionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            'type' => ['required', 'string', Rule::enum(TransactionType::class)],

            // amount/price must arrive as JSON strings, not bare numbers: a
            // bare 10.50 is indistinguishable from 10.5 once PHP's
            // json_decode turns it into a float, so decimal:2 alone can't
            // enforce "exactly 2 decimal places" against it.
            'amount' => [
                'bail',
                'required_if:type,deposit,withdrawal',
                'prohibited_if:type,buy,sell',
                'string',
                'numeric',
                'decimal:2',
                // decimal:2 alone accepts "+10.50" and ".50"; this regex
                // pins the canonical shape (leading digit, exactly 2 places).
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

    public function withValidator(ValidatorContract $validator): void
    {
        $validator->after(function (ValidatorContract $validator) {
            // Derived from rules(), not a hand-maintained list, so the two
            // can't drift apart.
            $allowed = array_keys($this->rules());
            $unknown = array_diff(array_keys($this->all()), $allowed);

            foreach ($unknown as $field) {
                $validator->errors()->add($field, 'Unknown field.');
            }
        });
    }

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
