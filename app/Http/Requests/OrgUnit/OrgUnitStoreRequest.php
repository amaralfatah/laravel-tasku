<?php

namespace App\Http\Requests\OrgUnit;

use App\Models\OrgUnit;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class OrgUnitStoreRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'type' => ['nullable', 'string', Rule::in(['company', 'division', 'sub_division', 'team'])],
            // Any node the viewer can reach: the whole master tree for the
            // operator, the workspace's own subtree for everyone else, which
            // `parent()` resolves through the global scope.
            'parent_id' => ['nullable', 'integer', 'exists:org_units,id'],
        ];
    }

    /**
     * The parent unit, resolved through the tenant scope so cross-tenant ids fail.
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
