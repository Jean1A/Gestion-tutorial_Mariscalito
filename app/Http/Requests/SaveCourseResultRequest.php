<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCourseResultRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('grade', $this->route('offering'));
    }

    public function rules(): array
    {
        return ['enrollment_id' => ['required', 'integer', 'exists:enrollments,id'], 'academic_period_id' => ['required', 'integer', 'exists:academic_periods,id'], 'achievement' => ['required', Rule::in(['AD', 'A', 'B', 'C'])], 'is_published' => ['required', 'boolean'], 'reason' => ['nullable', 'string', 'max:500']];
    }
}
