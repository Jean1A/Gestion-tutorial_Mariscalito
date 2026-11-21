<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AssignmentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-assignments') && ($this->input('type') !== 'tutor' || $this->user()->can('coordinate-tutors'));
    }

    public function rules(): array
    {
        return ['type' => ['required', Rule::in(['guardian', 'tutor', 'assistant'])], 'user_dni' => ['required', 'regex:/^[0-9]{8}$/', Rule::exists('users', 'dni')->where('is_active', true)->whereNull('deleted_at')], 'student_dni' => ['required_if:type,guardian', 'nullable', 'regex:/^[0-9]{8}$/', 'exists:students,dni'], 'section_id' => ['required_if:type,tutor', 'nullable', 'integer', 'exists:sections,id'], 'grade_id' => ['required_if:type,assistant', 'nullable', 'integer', 'exists:grades,id'], 'school_year_id' => ['required_if:type,assistant', 'nullable', 'integer', 'exists:school_years,id'], 'relationship' => ['required_if:type,guardian', 'nullable', 'string', 'max:60'], 'is_active' => ['required', 'boolean'], 'identity_verified' => [$this->input('type') === 'guardian' && $this->boolean('is_active') ? 'accepted' : 'nullable']];
    }
}
