<?php

namespace App\Http\Requests\Products;

class UpdateProductRequest extends StoreProductRequest
{
    /** @return array<string, list<string>> */
    public function rules(): array
    {
        $rules = parent::rules();

        foreach ($rules as $field => $fieldRules) {
            $rules[$field] = ['sometimes', ...$fieldRules];
        }

        return $rules;
    }
}
