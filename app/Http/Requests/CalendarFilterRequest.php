<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CalendarFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-academics');
    }

    public function rules(): array
    {
        return ['school_year_id' => ['nullable', 'integer', Rule::exists('school_years', 'id')->whereNull('closed_at')], 'month' => ['nullable', 'date_format:Y-m']];
    }
}
