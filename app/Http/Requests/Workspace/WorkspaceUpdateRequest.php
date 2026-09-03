<?php

namespace App\Http\Requests\Workspace;

use App\Models\Workspace;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class WorkspaceUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // The holding this company operates under. A workspace may not be
            // its own parent, and a cycle would make the group unreadable, so
            // only a workspace outside this one's own subtree qualifies.
            'parent_id' => [
                'sometimes',
                'nullable',
                'integer',
                'exists:workspaces,id',
                Rule::notIn($this->closedGroupIds()),
            ],
            // The node of the platform org tree this workspace runs. Any node
            // qualifies: the operator is the one who decides the slice.
            'root_org_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:org_units,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * The workspace itself plus everything already under it: putting it below
     * one of its own companies would close the group into a loop.
     *
     * @return array<int, int>
     */
    protected function closedGroupIds(): array
    {
        $workspace = $this->route('workspace');

        if (! $workspace instanceof Workspace) {
            return [];
        }

        return $workspace->descendants()
            ->pluck('id')
            ->push($workspace->id)
            ->all();
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama workspace',
            'parent_id' => 'holding',
            'root_org_unit_id' => 'unit organisasi',
            'is_active' => 'status aktif',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'parent_id.not_in' => 'Workspace tidak bisa berada di bawah dirinya sendiri atau di bawah anak perusahaannya.',
        ];
    }
}
