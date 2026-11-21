<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveFamilyResourceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-family');
    }

    public function rules(): array
    {
        if ($this->routeIs('family.manage.meetings.store', 'family.manage.meetings.update')) {
            return ['title' => ['required', 'string', 'max:150'], 'section_id' => ['nullable', 'integer', 'exists:sections,id'], 'scheduled_at' => ['required', 'date_format:Y-m-d\TH:i'], 'location' => ['required', 'string', 'max:150'], 'is_published' => ['required', 'boolean'], 'is_active' => ['required', 'boolean']];
        }

        return ['title' => ['required', 'string', 'max:150'], 'description' => ['required', 'string', 'max:3000'], 'is_published' => ['required', 'boolean'], 'is_active' => ['required', 'boolean'], 'lessons' => ['required', 'array', 'min:1', 'max:20'], 'lessons.*.title' => ['required', 'string', 'max:150'], 'lessons.*.content' => ['required', 'string', 'max:20000']];
    }
}
