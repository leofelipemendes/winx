<?php

namespace App\Http\Requests\Products;

use Illuminate\Foundation\Http\FormRequest;

class IndexProductRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $inStock = $this->input('in_stock');

        if (is_string($inStock) && in_array(strtolower($inStock), ['true', 'false'], true)) {
            $this->merge(['in_stock' => strtolower($inStock) === 'true']);
        }
    }

    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = [
            'q' => ['sometimes', 'required', 'string', 'max:255'],
            'category' => ['sometimes', 'required', 'string', 'max:255'],
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'min_price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'max_price' => ['sometimes', 'required', 'numeric', 'decimal:0,2', 'min:0', 'max:9999999999.99'],
            'in_stock' => ['sometimes', 'required', 'boolean'],
            'per_page' => ['sometimes', 'required', 'integer', 'min:1', 'max:100'],
            'page' => ['sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
        ];

        if ($this->filled('min_price')) {
            $rules['max_price'][] = 'gte:min_price';
        }

        return $rules;
    }

    /** @return array{q?: string, category?: string, name?: string, min_price?: string, max_price?: string, in_stock?: bool} */
    public function filters(): array
    {
        $filters = $this->safe()->only(['q', 'category', 'name', 'min_price', 'max_price', 'in_stock']);

        foreach (['min_price', 'max_price'] as $field) {
            if (isset($filters[$field])) {
                $filters[$field] = (string) $filters[$field];
            }
        }

        if (isset($filters['in_stock'])) {
            $filters['in_stock'] = (bool) $filters['in_stock'];
        }

        return $filters;
    }
}
