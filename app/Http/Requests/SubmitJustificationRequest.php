<?php

namespace App\Http\Requests;

use App\Services\AccessScope;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SubmitJustificationRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('guardian-portal') && app(AccessScope::class)->students($this->user())->whereKey($this->route('record')->enrollment->student_id)->exists();
    }

    public function rules(): array
    {
        return ['reason' => ['required', Rule::in(['illness', 'medical_appointment', 'family', 'travel', 'emergency', 'other'])], 'description' => ['required', 'string', 'min:10', 'max:2000'], 'document' => ['nullable', 'file', 'extensions:pdf,jpg,jpeg,png', 'mimes:pdf,jpg,jpeg,png', 'max:5120']];
    }
}
