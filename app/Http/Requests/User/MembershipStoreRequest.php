<?php

namespace App\Http\Requests\User;

use App\Enums\WorkspaceRole;
use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Places an account in a workspace from the operator console.
 *
 * Unlike the tenant-side requests this validates against the whole platform:
 * the operator has no membership to scope against, so `exists` rules here are
 * bare on purpose. The one boundary that still holds is the org unit, which
 * must sit inside the subtree the chosen workspace runs.
 */
class MembershipStoreRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'workspace_id' => ['required', 'integer', Rule::exists('workspaces', 'id')],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
            'title' => ['nullable', 'string', 'max:100'],
            'org_unit_id' => ['nullable', 'integer', Rule::exists('org_units', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('org_unit_id') === null) {
                return;
            }

            $workspace = Workspace::find($this->integer('workspace_id'));

            if ($workspace === null || $workspace->orgUnits()->whereKey($this->integer('org_unit_id'))->doesntExist()) {
                $validator->errors()->add(
                    'org_unit_id',
                    'Unit itu berada di luar struktur yang dijalankan workspace ini.',
                );
            }
        });
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'workspace_id' => 'workspace',
            'role' => 'role',
            'title' => 'jabatan',
            'org_unit_id' => 'unit',
        ];
    }
}
