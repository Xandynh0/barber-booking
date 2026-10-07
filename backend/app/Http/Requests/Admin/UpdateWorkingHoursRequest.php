<?php

namespace App\Http\Requests\Admin;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Validator;

/**
 * Saving the weekly schedule is a full replace: the request must describe
 * all 7 weekdays every time (a day with an empty `periods` array is a day
 * off), which is what makes the atomic "delete all, then recreate" strategy
 * in WorkingHourController safe — there is no partial-update semantics to
 * get wrong here, unlike service_ids on professionals.
 */
class UpdateWorkingHoursRequest extends FormRequest
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
            'days' => ['required', 'array', 'size:7'],
            'days.*.weekday' => ['required', 'integer', 'between:0,6', 'distinct'],
            'days.*.periods' => ['present', 'array'],
            'days.*.periods.*.start_time' => ['required', 'date_format:H:i'],
            'days.*.periods.*.end_time' => ['required', 'date_format:H:i'],
        ];
    }

    /**
     * Overlap and start<end are checked against the payload itself (no
     * database read involved), so this runs as ordinary input validation
     * before the controller ever opens a transaction — see
     * docs/planejamento-barbearia-mvp.md section 5 on only reading business
     * data after the business_settings lock, which doesn't apply here
     * because nothing here is a business read.
     */
    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            foreach ($this->input('days', []) as $dayIndex => $day) {
                $periods = $day['periods'] ?? [];
                if (! is_array($periods)) {
                    continue;
                }

                $withValidTimes = [];
                foreach ($periods as $periodIndex => $period) {
                    $start = $period['start_time'] ?? null;
                    $end = $period['end_time'] ?? null;

                    if (! is_string($start) || ! is_string($end)) {
                        continue;
                    }

                    if ($start >= $end) {
                        $validator->errors()->add(
                            "days.{$dayIndex}.periods.{$periodIndex}.end_time",
                            __('errors.period_end_before_start'),
                        );

                        continue;
                    }

                    $withValidTimes[] = ['index' => $periodIndex, 'start' => $start, 'end' => $end];
                }

                usort($withValidTimes, fn (array $a, array $b) => $a['start'] <=> $b['start']);

                for ($i = 1; $i < count($withValidTimes); $i++) {
                    if ($withValidTimes[$i]['start'] < $withValidTimes[$i - 1]['end']) {
                        $validator->errors()->add(
                            "days.{$dayIndex}.periods.{$withValidTimes[$i]['index']}.start_time",
                            __('errors.periods_overlap'),
                        );
                    }
                }
            }
        });
    }
}
