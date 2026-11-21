<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CourseFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('view', $this->route('offering'));
    }

    public function rules(): array
    {
        return ['date' => ['nullable', 'date_format:Y-m-d', 'before_or_equal:today']];
    }
}
