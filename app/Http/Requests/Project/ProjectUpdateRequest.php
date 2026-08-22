<?php

namespace App\Http\Requests\Project;

use App\Concerns\ScopesValidationToWorkspace;
use App\Enums\ProjectStatus;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectUpdateRequest extends FormRequest
{
    use ScopesValidationToWorkspace;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'org_unit_id' => ['sometimes', 'required', 'integer', $this->existsAsOrgUnit()],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
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
        ];
    }
}
