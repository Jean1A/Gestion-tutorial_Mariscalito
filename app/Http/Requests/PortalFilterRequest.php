<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortalFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('student')) && $this->user()->permits('attendance.view');
    }

    public function rules(): array
    {
        return ['school_year_id' => ['nullable', 'integer', 'exists:school_years,id'], 'period_id' => ['nullable', 'integer', 'exists:academic_periods,id'], 'from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from']];
    }
}
