<?php

namespace App\Http\Requests;

use App\Models\Student;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveStudentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->route('student') ? $this->user()->can('update', $this->route('student')) : $this->user()->can('create', Student::class);
    }

    public function rules(): array
    {
        return [
            'dni' => ['required', 'regex:/^[0-9]{8}$/', Rule::unique('students')->ignore($this->route('student'))],
            'first_name' => ['required', 'string', 'max:100'], 'last_name' => ['required', 'string', 'max:140'],
            'birth_date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today'], 'is_active' => ['required', 'boolean'],
            'section_id' => ['required', 'integer', Rule::exists('sections', 'id')->where('is_active', true)],
            'starts_on' => ['required', 'date_format:Y-m-d'], 'ends_on' => ['nullable', 'required_unless:status,active', 'date_format:Y-m-d', 'after_or_equal:starts_on'],
            'status' => ['required', Rule::in(['active', 'withdrawn', 'transferred'])],
        ];
    }
}
