<?php

namespace App\Http\Requests\Member;

use App\Concerns\ScopesValidationToWorkspace;
use App\Enums\WorkspaceRole;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class MemberUpdateRequest extends FormRequest
{
    use ScopesValidationToWorkspace;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::in($this->assignableRoleValues())],
            'title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'org_unit_id' => ['sometimes', 'nullable', 'integer', $this->existsAsOrgUnit()],
            'manager_id' => ['sometimes', 'nullable', 'integer', $this->existsAsWorkspaceMember()],
        ];
    }

    /**
     * Roles the person editing may hand out — never one at a higher rank than
     * their own (ORG-8).
     *
     * @return array<int, string>
     */
    protected function assignableRoleValues(): array
    {
        $viewer = app(Tenancy::class)->member();

        return array_map(
            fn (WorkspaceRole $role): string => $role->value,
            $viewer?->role->assignableRoles() ?? [],
        );
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'role' => 'role',
            'title' => 'jabatan',
            'org_unit_id' => 'unit',
            'manager_id' => 'atasan langsung',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'Anda tidak bisa memberikan role di atas role Anda sendiri.',
        ];
    }
}
