<?php

namespace App\Http\Requests\Workspace;

use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Starting one's own workspace, the self-serve path.
 *
 * Open to anyone who belongs to nowhere yet. Someone whose only workspace was
 * switched off is deliberately excluded: that is an administrative decision,
 * and letting them start a fresh one would be a way around it. A super admin
 * operates the platform and never works inside a workspace (SA-4).
 */
class WorkspaceStartRequest extends FormRequest
{
    public function authorize(): bool
    {
        $user = $this->user();

        return $user !== null
            && ! $user->is_super_admin
            && $user->workspaceMembers()->withoutGlobalScopes()->doesntExist();
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama workspace',
        ];
    }
}
