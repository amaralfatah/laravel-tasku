<?php

namespace App\Http\Requests;

use App\Concerns\PasswordValidationRules;
use App\Models\Invitation;
use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class InvitationAcceptRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * Get the validation rules that apply to the request.
     *
     * Only invitations for an address without an account ask for name and
     * password; an existing user just confirms.
     *
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        if (! $this->needsAccount()) {
            return [];
        }

        return [
            'name' => ['required', 'string', 'max:255'],
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'password' => 'kata sandi',
        ];
    }

    protected function needsAccount(): bool
    {
        $invitation = Invitation::withoutGlobalScopes()
            ->where('token', (string) $this->route('token'))
            ->first();

        return $invitation !== null
            && ! User::where('email', $invitation->email)->exists();
    }
}
