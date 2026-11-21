<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ParentSurveyRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('guardian-portal');
    }

    public function rules(): array
    {
        return ['rating' => ['required', 'integer', 'between:1,5'], 'comment' => ['nullable', 'string', 'max:1000']];
    }
}
