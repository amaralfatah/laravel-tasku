<?php

namespace App\Http\Requests\User;

use App\Actions\ChangeMemberRole;
use App\Enums\WorkspaceRole;
use App\Models\WorkspaceMember;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Edits an existing membership from the operator console.
 *
 * Every role is on the table, Owner included — the operator sits outside the
 * ladder that `WorkspaceRole::mayAssign()` enforces between members. What is
 * not on the table is leaving a workspace with no Owner, and that rule lives
 * in {@see ChangeMemberRole}.
 */
class MembershipUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'role' => ['sometimes', Rule::enum(WorkspaceRole::class)],
            'title' => ['sometimes', 'nullable', 'string', 'max:100'],
            'org_unit_id' => ['sometimes', 'nullable', 'integer', Rule::exists('org_units', 'id')],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            if ($validator->errors()->isNotEmpty() || $this->input('org_unit_id') === null) {
                return;
            }

            $workspace = $this->member()->workspace;

            if ($workspace->orgUnits()->whereKey($this->integer('org_unit_id'))->doesntExist()) {
                $validator->errors()->add(
                    'org_unit_id',
                    'Unit itu berada di luar struktur yang dijalankan workspace ini.',
                );
            }
        });
    }

    /**
     * The membership being edited, as bound to the route.
     */
    protected function member(): WorkspaceMember
    {
        /** @var WorkspaceMember $member */
        $member = $this->route('member');

        return $member;
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
        ];
    }
}
