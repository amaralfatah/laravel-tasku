<?php

namespace App\Http\Requests\Member;

use App\Enums\ScopeType;
use App\Enums\WorkspaceRole;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class MemberUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::enum(WorkspaceRole::class)],
            'org_unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('org_units', 'id')],
            'scope_type' => ['sometimes', Rule::enum(ScopeType::class)],
            'scope_org_unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('org_units', 'id')],
            'manager_id' => ['sometimes', 'nullable', 'integer', Rule::exists('users', 'id')],
        ];
    }

    /**
     * A subtree scope is meaningless without the unit it is rooted at (ORG-12).
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $isSubtree = $this->input('scope_type') === ScopeType::UnitSubtree->value;

                if ($isSubtree && $this->input('scope_org_unit_id') === null) {
                    $validator->errors()->add(
                        'scope_org_unit_id',
                        'Pilih unit yang menjadi akar cakupan pemantauan.',
                    );
                }
            },
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'role' => 'role',
            'org_unit_id' => 'unit',
            'scope_type' => 'cakupan pemantauan',
            'scope_org_unit_id' => 'unit cakupan',
            'manager_id' => 'atasan langsung',
        ];
    }
}
