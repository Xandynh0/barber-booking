<?php

namespace App\Http\Requests\Admin;

use App\Models\Appointment;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * GET /api/v1/admin/agenda?date=YYYY-MM-DD[&professional_id=N][&status[]=...]
 * The date is a calendar date in the barbershop's timezone; `status`
 * keeps only the reservations in those statuses.
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
            'status' => ['sometimes', 'array'],
            'status.*' => ['string', Rule::in(Appointment::STATUSES)],
        ];
    }

    /**
     * @return array<int, string>|null
     */
    public function statuses(): ?array
    {
        $statuses = $this->validated('status');

        return $statuses === null ? null : array_values(array_unique($statuses));
    }
}
