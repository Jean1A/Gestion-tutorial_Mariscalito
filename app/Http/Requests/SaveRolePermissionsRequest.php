<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class SaveRolePermissionsRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('manage-settings');
    }

    public function rules(): array
    {
        return ['permissions' => ['nullable', 'array', 'max:10'], 'permissions.*' => ['integer', 'distinct', 'exists:permissions,id']];
    }
}
