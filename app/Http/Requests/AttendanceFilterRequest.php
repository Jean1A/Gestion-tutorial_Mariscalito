<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class AttendanceFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->permits('attendance.view') && $this->user()->can('view', $this->route('section'));
    }

    public function rules(): array
    {
        return ['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']];
    }
}
