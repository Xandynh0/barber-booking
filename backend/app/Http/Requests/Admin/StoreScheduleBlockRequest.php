<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class StoreScheduleBlockRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Only format is validated here. Whether `professional_id` actually
     * exists is a business read that must happen inside the locked
     * transaction, not here — see ScheduleBlockController, same reasoning
     * as service_ids in StoreProfessionalRequest.
     *
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'scope' => ['required', Rule::in(['professional', 'shop'])],
            'professional_id' => ['required_if:scope,professional', 'prohibited_if:scope,shop', 'integer'],
            'starts_at' => ['required', 'date'],
            'ends_at' => ['required', 'date', 'after:starts_at'],
            'reason' => ['nullable', 'string', 'max:500'],
        ];
    }
}
