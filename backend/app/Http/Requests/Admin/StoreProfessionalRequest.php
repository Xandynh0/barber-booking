<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

class StoreProfessionalRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only format is validated here (shape, duplicates within the submitted
     * array). Whether each service_id actually exists is a business read
     * that must happen inside the locked transaction, not here — see
     * ProfessionalController.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:2000'],
            'is_active' => ['sometimes', 'boolean'],
            'service_ids' => ['sometimes', 'array'],
            'service_ids.*' => ['integer', 'distinct'],
        ];
    }
}
