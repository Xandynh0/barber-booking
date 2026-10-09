<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;

/**
 * GET /api/v1/admin/agenda?date=YYYY-MM-DD[&professional_id=N]. The date is
 * a calendar date in the barbershop's timezone.
 */
class AgendaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'date' => ['required', 'date_format:Y-m-d'],
            'professional_id' => ['nullable', 'integer', 'exists:professionals,id'],
        ];
    }
}
