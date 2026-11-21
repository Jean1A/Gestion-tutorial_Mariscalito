<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveCourseContentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('grade', $this->route('offering'));
    }

    public function rules(): array
    {
        return ['content' => ['required', 'string', 'max:20000']];
    }
}
