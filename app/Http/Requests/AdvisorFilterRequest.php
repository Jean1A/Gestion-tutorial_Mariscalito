<?php

namespace App\Http\Requests;

use App\Services\AdvisorPortalService;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AdvisorFilterRequest extends FormRequest
{
    public function authorize(): bool
    {
        return $this->user()->can('advisor-portal');
    }

    public function rules(): array
    {
        return ['section_id' => ['nullable', 'integer', 'exists:sections,id'], 'q' => ['nullable', 'string', 'max:100'], 'status' => ['nullable', Rule::in(['all', 'pending', 'approved', 'rejected'])]];
    }

    public function selectedSection(): ?int
    {
        $id = $this->validated('section_id');
        if ($id) {
            abort_unless(app(AdvisorPortalService::class)->sections($this->user())->whereKey($id)->exists(), 403);
        }

        return $id ? (int) $id : null;
    }
}
