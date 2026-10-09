<?php

namespace App\Http\Requests;

use App\Services\Booking\ContactNormalizer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;

/**
 * POST /api/v1/public/appointments (docs/planejamento-barbearia-mvp.md,
 * seção 11). Only format is validated here; every business rule is
 * re-checked by AppointmentBooker inside the locked transaction.
 *
 * Only the fields below ever reach the booker (`booking()` builds its input
 * from validated data), so anything else in the body — status, source,
 * snapshots, ends_at, price, public_id — is ignored, never assigned.
 */
class StorePublicAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * The idempotency key travels in the `Idempotency-Key` header; it is
     * copied into the validated data so it gets the same 422 contract as
     * every other field. A body field with that name is overwritten.
     */
    protected function prepareForValidation(): void
    {
        $this->merge(['idempotency_key' => $this->header('Idempotency-Key')]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:255', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'service_id' => ['required', 'integer'],
            'professional_id' => ['required', 'integer'],
            // ISO 8601 with an explicit offset (seção 11): a bare local
            // time would be ambiguous about which timezone the client meant.
            'starts_at' => ['required', 'string', 'date', 'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+\-]\d{2}:\d{2})$/'],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['required', 'string', 'max:254', 'email:rfc'],
            'customer_phone' => ['required', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail) {
                if (is_string($value) && ContactNormalizer::phone($value) === null) {
                    $fail(__('errors.invalid_phone'));
                }
            }],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'starts_at.regex' => __('errors.starts_at_needs_offset'),
        ];
    }

    /**
     * Validated input in the shape AppointmentBooker expects: contacts in
     * canonical form, start converted to UTC.
     *
     * @return array{service_id: int, professional_id: int, starts_at: CarbonImmutable, customer_name: string, customer_email: string, customer_phone: string}
     */
    public function booking(): array
    {
        $validated = $this->validated();

        return [
            'service_id' => (int) $validated['service_id'],
            'professional_id' => (int) $validated['professional_id'],
            'starts_at' => CarbonImmutable::parse($validated['starts_at'])->utc(),
            'customer_name' => trim($validated['customer_name']),
            'customer_email' => ContactNormalizer::email($validated['customer_email']),
            'customer_phone' => ContactNormalizer::phone($validated['customer_phone']),
        ];
    }

    public function idempotencyKey(): string
    {
        return $this->validated()['idempotency_key'];
    }
}
