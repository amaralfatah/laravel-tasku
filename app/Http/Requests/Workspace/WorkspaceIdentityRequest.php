<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * The Owner's half of the workspace row: what the entity is called and the
 * mark it goes by, and nothing else.
 *
 * Deliberately not {@see WorkspaceUpdateRequest}, which also accepts
 * `parent_id`, `root_org_unit_id` and `is_active`. Reusing that one here would
 * hand a customer the group structure and their own activation switch through
 * fields the form never shows.
 */
class WorkspaceIdentityRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'logo' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp', 'max:2048'],
            'remove_logo' => ['nullable', 'boolean'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama workspace',
            'logo' => 'logo workspace',
        ];
    }
}
