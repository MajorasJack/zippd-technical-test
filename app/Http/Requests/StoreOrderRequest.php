<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StoreOrderRequest extends FormRequest
{
    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, array<int, string>>
     */
    public function rules(): array
    {
        return [
            'partner_reference' => ['required', 'string', 'max:100'],
            'idempotency_key' => ['nullable', 'string', 'max:255'],
            ...$this->addressRules('collection_address'),
            ...$this->addressRules('delivery_address'),
            'parcels' => ['required', 'array', 'min:1', 'max:50'],
            'parcels.*.weight_grams' => ['required', 'integer', 'between:1,50000'],
        ];
    }

    /**
     * Copy the Idempotency-Key header into the data so it is validated with the body.
     *
     * An empty header counts as no key, so it can't match another order's empty key.
     */
    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => filled($this->header('Idempotency-Key'))
                ? $this->header('Idempotency-Key')
                : null,
        ]);
    }

    /**
     * The rules for one address, which is stored as JSON and so only accepts the known keys.
     *
     * @return array<string, array<int, string>>
     */
    private function addressRules(string $field): array
    {
        return [
            $field => ['required', 'array:line_1,line_2,city,postcode,country_code'],
            sprintf('%s.line_1', $field) => ['required', 'string', 'max:255'],
            sprintf('%s.line_2', $field) => ['nullable', 'string', 'max:255'],
            sprintf('%s.city', $field) => ['required', 'string', 'max:255'],
            sprintf('%s.postcode', $field) => ['required', 'string', 'max:255'],
            sprintf('%s.country_code', $field) => ['required', 'string', 'size:2', 'alpha:ascii'],
        ];
    }
}
