<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class PublicRecipeFiltersRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function messages(): array
    {
        $messages = [];
        foreach (array_keys($this->rules()) as $field) {
            foreach (['string', 'array', 'in', 'max', 'integer', 'between', 'distinct', 'required'] as $rule) {
                $messages[$field.'.'.$rule] = __('discovery.invalid_filters');
            }
        }
        $messages['ingredients.max'] = __('discovery.ingredient_limit');

        return $messages;
    }

    public function rules(): array
    {
        return [
            'q' => ['nullable', 'string', 'max:100'],
            'category' => ['nullable', 'string', 'max:100'],
            'tag' => ['nullable', 'string', 'max:100'],
            'difficulty' => ['nullable', 'string', 'max:100'],
            'cuisine' => ['nullable', 'string', 'max:100'],
            'method' => ['nullable', 'string', 'max:100'],
            'dietary' => ['nullable', 'string', 'max:100'],
            'time' => ['nullable', Rule::in(['30', '60', '90'])],
            'sort' => ['nullable', Rule::in(['featured', 'newest', 'quickest', 'rating'])],
            'collection' => ['nullable', Rule::in(['local', 'world', 'seasonal'])],
            'rating' => ['nullable', Rule::in(['3', '4', '5'])],
            'ingredients' => ['sometimes', 'array', 'max:5'],
            'ingredients.*' => ['required', 'string', 'max:100', 'distinct:strict'],
            'recipes_page' => ['sometimes', 'integer', 'between:1,10000'],
        ];
    }
}
