<?php

namespace App\Http\Requests;

use App\Enums\AttendanceStatus;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class RecordAttendanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('recordAttendance', $this->route('section'));
    }

    public function rules(): array
    {
        return ['date' => ['required', 'date_format:Y-m-d', 'before_or_equal:today'], 'revision' => ['required', 'integer', 'min:0'], 'records' => ['required', 'array', 'min:1', 'max:200'], 'records.*.enrollment_id' => ['required', 'integer', 'distinct'], 'records.*.status' => ['required', Rule::enum(AttendanceStatus::class)], 'records.*.arrival_time' => ['nullable', 'date_format:H:i'], 'records.*.justification' => ['nullable', 'string', 'max:500']];
    }
}
