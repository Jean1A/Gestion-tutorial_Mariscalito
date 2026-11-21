<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SendCommunicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->permits('communications.send');
    }

    public function rules(): array
    {
        return ['school_year_id' => ['required', 'integer', Rule::exists('school_years', 'id')->where('is_active', true)], 'type' => ['required', Rule::in(['notice', 'appointment'])], 'target' => ['required', Rule::in(['student', 'section', 'grade', 'school'])], 'student_dni' => ['required_if:target,student', 'nullable', 'regex:/^[0-9]{8}$/'], 'section_id' => ['required_if:target,section', 'nullable', 'integer', 'exists:sections,id'], 'grade_id' => ['required_if:target,grade', 'nullable', 'integer', 'exists:grades,id'], 'title' => ['required', 'string', 'max:150'], 'body' => ['required', 'string', 'max:5000'], 'scheduled_at' => ['required_if:type,appointment', 'nullable', 'date_format:Y-m-d\TH:i', 'after:now'], 'location' => ['required_if:type,appointment', 'nullable', 'string', 'max:180']];
    }
}
