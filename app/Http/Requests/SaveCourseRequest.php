<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCourseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-courses');
    }

    public function rules(): array
    {
        return ['name' => ['required', 'string', 'max:100'], 'icon' => ['required', Rule::in(['book', 'calculator', 'flask', 'globe', 'palette', 'music-note', 'activity'])], 'section_id' => ['required', 'integer', 'exists:sections,id'], 'teacher_id' => ['required', 'integer', Rule::exists('users', 'id')->where('is_active', true)->whereNull('deleted_at')], 'content' => ['nullable', 'string', 'max:20000'], 'is_active' => ['required', 'boolean'], 'is_tutoring' => ['sometimes', 'boolean']];
    }
}
