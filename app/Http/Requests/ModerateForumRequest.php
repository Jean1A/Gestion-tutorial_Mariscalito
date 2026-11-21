<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ModerateForumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-family');
    }

    public function rules(): array
    {
        return ['status' => ['required', Rule::in(['published', 'rejected'])]];
    }
}
