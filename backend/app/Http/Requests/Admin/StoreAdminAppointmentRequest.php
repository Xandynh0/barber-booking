<?php

namespace App\Http\Requests\Admin;

use App\Services\Booking\AppointmentBooker;
use App\Services\Booking\ContactNormalizer;
use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * POST /api/v1/admin/appointments (docs/planejamento-barbearia-mvp.md,
 * seções 3 e 11). Format only; every business rule is re-checked by
 * AppointmentBooker::bookAdmin() inside the locked transaction.
 *
 * - `mode=scheduled` requires `starts_at` (ISO 8601 with offset);
 *   `mode=now` refuses it — the start of "Atender agora" is decided by the
 *   server, never by the browser.
 * - Name is required; e-mail and phone are optional (seção 1). Contacts
 *   are stored in canonical form, like the public flow.
 * - Same `Idempotency-Key` header as the public endpoint; keys are unique
 *   across both, and the fingerprint binds a key to the admin context.
 */
class StoreAdminAppointmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'idempotency_key' => $this->header('Idempotency-Key'),
            'customer_email' => $this->blankToNull('customer_email'),
            'customer_phone' => $this->blankToNull('customer_phone'),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'idempotency_key' => ['required', 'string', 'min:8', 'max:255', 'regex:/^[A-Za-z0-9._:\-]+$/'],
            'mode' => ['required', 'string', Rule::in([AppointmentBooker::MODE_SCHEDULED, AppointmentBooker::MODE_NOW])],
            'service_id' => ['required', 'integer'],
            'professional_id' => ['required', 'integer'],
            'starts_at' => [
                'required_if:mode,'.AppointmentBooker::MODE_SCHEDULED,
                'prohibited_if:mode,'.AppointmentBooker::MODE_NOW,
                'nullable', 'string', 'date',
                'regex:/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}(:\d{2}(\.\d+)?)?(Z|[+\-]\d{2}:\d{2})$/',
            ],
            'customer_name' => ['required', 'string', 'max:120'],
            'customer_email' => ['nullable', 'string', 'max:254', 'email:rfc'],
            'customer_phone' => ['nullable', 'string', 'max:32', function (string $attribute, mixed $value, Closure $fail) {
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
     * @return array{mode: string, service_id: int, professional_id: int, starts_at: ?CarbonImmutable, customer_name: string, customer_email: ?string, customer_phone: ?string}
     */
    public function booking(): array
    {
        $validated = $this->validated();
        $email = $validated['customer_email'] ?? null;
        $phone = $validated['customer_phone'] ?? null;

        return [
            'mode' => $validated['mode'],
            'service_id' => (int) $validated['service_id'],
            'professional_id' => (int) $validated['professional_id'],
            'starts_at' => $validated['mode'] === AppointmentBooker::MODE_SCHEDULED
                ? CarbonImmutable::parse($validated['starts_at'])->utc()
                : null,
            'customer_name' => trim($validated['customer_name']),
            'customer_email' => $email === null ? null : ContactNormalizer::email($email),
            'customer_phone' => $phone === null ? null : ContactNormalizer::phone($phone),
        ];
    }

    public function idempotencyKey(): string
    {
        return $this->validated()['idempotency_key'];
    }

    private function blankToNull(string $key): mixed
    {
        $value = $this->input($key);

        return is_string($value) && trim($value) === '' ? null : $value;
    }
}
