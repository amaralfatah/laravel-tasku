<?php

namespace App\Http\Requests\Invitation;

use App\Enums\WorkspaceRole;
use App\Models\User;
use App\Models\WorkspaceMember;
use App\Support\Tenancy;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

class InvitationStoreRequest extends FormRequest
{
    /**
     * Checked before the rules run, so someone who may not invite at all gets
     * a refusal rather than a complaint about the role field — a Viewer can
     * hand out no role, which would otherwise read as a rank error.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('manage', WorkspaceMember::class);
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        $viewer = app(Tenancy::class)->member();

        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::in(array_map(
                fn (WorkspaceRole $role): string => $role->value,
                $viewer?->role->assignableRoles() ?? [],
            ))],
        ];
    }

    /**
     * Reject an address that is already a member of this workspace.
     *
     * @return array<int, \Closure>
     */
    public function after(): array
    {
        return [
            function (Validator $validator): void {
                $email = (string) $this->input('email');
                $userId = User::where('email', $email)->value('id');

                if ($userId === null) {
                    return;
                }

                $alreadyMember = WorkspaceMember::query()
                    ->where('workspace_id', app(Tenancy::class)->id())
                    ->where('user_id', $userId)
                    ->exists();

                if ($alreadyMember) {
                    $validator->errors()->add('email', 'Pengguna ini sudah menjadi anggota workspace.');
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
            'email' => 'alamat email',
            'role' => 'role',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'role.in' => 'Anda tidak bisa mengundang seseorang dengan role di atas role Anda sendiri.',
        ];
    }
}
