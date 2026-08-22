<?php

namespace App\Http\Requests\Project;

use App\Concerns\ScopesValidationToWorkspace;
use App\Enums\ProjectStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectStoreRequest extends FormRequest
{
    use ScopesValidationToWorkspace;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:5000'],
            'org_unit_id' => ['required', 'integer', $this->existsInWorkspace('org_units')],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer', $this->existsAsWorkspaceMember()],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama project',
            'description' => 'deskripsi',
            'org_unit_id' => 'unit',
            'status' => 'status',
            'member_ids' => 'anggota project',
        ];
    }
}
