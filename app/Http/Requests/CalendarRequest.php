<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CalendarRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-academics');
    }

    public function rules(): array
    {
        $rules = ['school_year_id' => ['required', 'integer', 'exists:school_years,id']];

        return $rules + ($this->routeIs('calendar.generate') ? ['week_days' => ['required', 'array', 'min:1'], 'week_days.*' => ['integer', 'between:1,7', 'distinct']] : ['date' => ['required', 'date_format:Y-m-d'], 'is_instructional' => ['required', 'boolean'], 'description' => ['nullable', 'string', 'max:160']]);
    }
}
