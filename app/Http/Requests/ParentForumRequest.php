<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ParentForumRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('guardian-portal');
    }

    public function rules(): array
    {
        return ['body' => ['required', 'string', 'min:10', 'max:2000']];
    }
}
