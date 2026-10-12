<?php

namespace App\Http\Requests;

use App\Models\Category;
use App\Support\ProductSearchText;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class ProductSearchRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'q' => ['bail', 'nullable', 'string', 'encoding:UTF-8', 'max:200'],
            'min_price' => ['bail', 'sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'max_price' => ['bail', 'sometimes', 'required', 'numeric', 'min:0', 'max:9999999999.99'],
            'category_id' => ['bail', 'sometimes', 'required', 'integer', 'min:1', Rule::exists(Category::class, 'id')],
            'available' => ['sometimes', 'required', 'boolean'],
            'sort_by' => ['sometimes', 'required', Rule::in(['price', 'created_at'])],
            'sort_order' => ['sometimes', 'required', Rule::in(['asc', 'desc'])],
            'page' => ['bail', 'sometimes', 'required', 'integer', 'min:1', 'max:2147483647'],
            'per_page' => ['bail', 'sometimes', 'required', 'integer', 'between:1,50'],
        ];
    }

    protected function prepareForValidation(): void
    {
        if (is_string($this->input('q')) && mb_check_encoding($this->input('q'), 'UTF-8')) {
            $this->merge(['q' => ProductSearchText::normalize($this->input('q'))]);
        }

        if (in_array($this->input('available'), ['true', 'false'], true)) {
            $this->merge(['available' => $this->input('available') === 'true']);
        }
    }

    /** @return array<callable(Validator): void> */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                if ($validator->errors()->hasAny(['min_price', 'max_price'])) {
                    return;
                }

                if ($this->filled(['min_price', 'max_price']) && (float) $this->input('min_price') > (float) $this->input('max_price')) {
                    $validator->errors()->add('min_price', 'The minimum price must not exceed the maximum price.');
                }
            },
        ];
    }
}
