<?php

namespace App\Http\Requests\OrgUnit;

use App\Concerns\ScopesValidationToWorkspace;
use App\Models\OrgUnit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrgUnitUpdateRequest extends FormRequest
{
    use ScopesValidationToWorkspace;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'type' => ['sometimes', 'nullable', 'string', Rule::in(['company', 'division', 'sub_division', 'team'])],
            'parent_id' => ['sometimes', 'nullable', 'integer', $this->existsInWorkspace('org_units')],
        ];
    }

    /**
     * The new parent unit, resolved through the tenant scope.
     */
    public function parent(): ?OrgUnit
    {
        $parentId = $this->validated('parent_id');

        return $parentId === null ? null : OrgUnit::query()->whereKey($parentId)->firstOrFail();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama unit',
            'type' => 'jenis unit',
            'parent_id' => 'unit induk',
        ];
    }
}
