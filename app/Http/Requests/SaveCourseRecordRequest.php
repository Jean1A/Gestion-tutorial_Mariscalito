<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class SaveCourseRecordRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('record', $this->route('offering'));
    }

    public function rules(): array
    {
        return ['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'revision' => ['required', 'integer', 'min:0'], 'records' => ['required', 'array', 'min:1', 'max:200'], 'records.*.enrollment_id' => ['required', 'integer', 'distinct'], 'records.*.status' => ['required', Rule::in(['present', 'late', 'unjustified_absence', 'justified_absence'])], 'records.*.arrival_time' => ['nullable', 'date_format:H:i']];
    }
}
