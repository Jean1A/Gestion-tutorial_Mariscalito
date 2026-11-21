<?php

namespace App\Http\Requests;

use App\Services\StudentSupportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SupportFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'dimension' => ['nullable', Rule::in(array_keys(StudentSupportService::DIMENSIONS))], 'school_year_id' => ['nullable', 'integer', Rule::exists('school_years', 'id')->whereNull('closed_at')], 'section_id' => ['nullable', 'integer', 'exists:sections,id'], 'student_id' => ['nullable', 'integer', 'exists:students,id'], 'kind' => ['nullable', Rule::in(array_keys(StudentSupportService::KINDS))], 'status' => ['nullable', Rule::in(['open', 'following', 'closed'])], 'q' => ['nullable', 'string', 'max:100']];
    }
}
