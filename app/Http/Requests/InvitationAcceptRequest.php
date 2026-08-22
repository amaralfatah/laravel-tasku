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
     * password. An address that already has an account confirms instead, and
     * the controller requires it to be signed in as that account first.
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

    /**
     * A dead invitation asks for nothing: the controller answers it with 410,
     * and demanding a password first would hide that behind a form error.
     */
    protected function needsAccount(): bool
    {
        $invitation = Invitation::withoutGlobalScopes()
            ->where('token', (string) $this->route('token'))
            ->first();

        return $invitation !== null
            && $invitation->isPending()
            && ! User::where('email', $invitation->email)->exists();
    }
}
