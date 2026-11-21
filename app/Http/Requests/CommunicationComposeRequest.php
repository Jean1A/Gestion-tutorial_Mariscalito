<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class CommunicationComposeRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->permits('communications.send');
    }

    public function rules(): array
    {
        return ['student_dni' => ['nullable', 'regex:/^[0-9]{8}$/']];
    }
}
