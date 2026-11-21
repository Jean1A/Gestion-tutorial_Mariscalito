<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class PortalCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('guardian-portal');
    }

    public function rules(): array
    {
        return ['from' => ['nullable', 'date_format:Y-m-d'], 'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'], 'period_id' => ['nullable', 'integer', 'exists:academic_periods,id']];
    }
}
