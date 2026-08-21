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
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'email' => ['required', 'string', 'email', 'max:255'],
            'role' => ['required', Rule::enum(WorkspaceRole::class)],
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
}
