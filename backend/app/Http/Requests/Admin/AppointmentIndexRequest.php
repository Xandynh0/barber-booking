<?php

namespace App\Http\Requests\Admin;

/**
 * GET /api/v1/admin/appointments?from=YYYY-MM-DD&to=YYYY-MM-DD
 * [&professional_id=N][&status[]=...] — reservations over a range of local
 * dates (both inclusive), at most MAX_DAYS long.
 */
class AppointmentIndexRequest extends AgendaRequest
{
    public const MAX_DAYS = 31;

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        $rules = parent::rules();
        unset($rules['date']);

        return [
            'from' => ['required', 'date_format:Y-m-d'],
            'to' => ['required', 'date_format:Y-m-d', 'after_or_equal:from', 'before_or_equal:'.$this->lastAllowedDay()],
            ...$rules,
        ];
    }

    private function lastAllowedDay(): string
    {
        $from = \DateTimeImmutable::createFromFormat('!Y-m-d', (string) $this->query('from'));

        return $from === false ? '9999-12-31' : $from->modify('+'.(self::MAX_DAYS - 1).' days')->format('Y-m-d');
    }
}
