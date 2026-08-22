<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class WorkspaceUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // The node of the platform org tree this workspace runs. Any node
            // qualifies: the operator is the one who decides the slice.
            'root_org_unit_id' => ['sometimes', 'nullable', 'integer', 'exists:org_units,id'],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama workspace',
            'root_org_unit_id' => 'unit organisasi',
            'is_active' => 'status aktif',
        ];
    }
}
