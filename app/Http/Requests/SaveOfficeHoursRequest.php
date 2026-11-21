<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveOfficeHoursRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-courses');
    }

    public function rules(): array
    {
        return ['user_id' => ['required', 'integer', 'exists:users,id'], 'office_hours' => ['nullable', 'string', 'max:500']];
    }
}
