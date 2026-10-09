<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Query string shared by GET /api/v1/public/availability and
 * GET /api/v1/admin/availability. Only format is validated here; whether
 * the service and professional exist, are active and are linked is checked
 * by the controller against the availability engine. There is deliberately
 * no "context" parameter — the route decides it.
 */
class AvailabilityRequest extends FormRequest
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
            'service_id' => ['required', 'integer'],
            'professional_id' => ['required', 'integer'],
            'date' => ['required', 'date_format:Y-m-d'],
        ];
    }
}
