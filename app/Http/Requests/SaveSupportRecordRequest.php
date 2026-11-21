<?php

namespace App\Http\Requests;

use App\Services\StudentSupportService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveSupportRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        $service = app(StudentSupportService::class);
        $record = $this->route('record');

        return $record ? $service->canEdit($this->user(), $record) : $service->canCreate($this->user());
    }

    public function rules(): array
    {
        return [
            'dimension' => ['nullable', Rule::in(array_keys(StudentSupportService::DIMENSIONS))],
            'family_summary' => ['nullable', 'string', 'max:5000'],
            'location' => ['nullable', 'string', 'max:160'],
            'student_id' => ['required', 'integer', 'exists:students,id'], 'section_id' => ['required', 'integer', 'exists:sections,id'],
            'kind' => ['required', Rule::in(array_keys(StudentSupportService::KINDS))], 'title' => ['required', 'string', 'max:160'], 'description' => ['required', 'string', 'min:10', 'max:10000'],
            'agreements' => ['nullable', 'string', 'max:5000'], 'visibility' => ['required', Rule::in(['reserved', 'internal', 'shared'])], 'severity' => ['required', Rule::in(['low', 'medium', 'high'])],
            'status' => ['required', Rule::in(['open', 'following', 'closed'])], 'occurred_on' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'],
            'due_on' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:occurred_on'],
            'course_offering_id' => ['nullable', 'integer', 'exists:course_offerings,id'], 'closure_note' => ['nullable', 'string', 'max:5000'], 'revision' => ['required', 'integer', 'min:0']];
    }
}
