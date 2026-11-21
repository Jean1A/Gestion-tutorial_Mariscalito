<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RespondCommunicationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('respond', $this->route('recipient'));
    }

    public function rules(): array
    {
        return ['response' => ['required', Rule::in(['confirmed', 'reschedule_requested'])], 'response_note' => ['required_if:response,reschedule_requested', 'nullable', 'string', 'max:500']];
    }
}
