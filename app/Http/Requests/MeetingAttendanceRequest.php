<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class MeetingAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-family');
    }

    public function rules(): array
    {
        return ['user_id' => ['required', 'integer', 'exists:users,id'], 'attended' => ['required', 'boolean']];
    }
}
