<?php

namespace App\Http\Requests\User;

use App\Concerns\PasswordValidationRules;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Sets a new password for someone else's account.
 *
 * No current password: the operator does not have it, which is the whole
 * reason this exists. The setting screen's own change flow still asks for it.
 */
class UserPasswordRequest extends FormRequest
{
    use PasswordValidationRules;

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'password' => $this->passwordRules(),
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'password' => 'kata sandi',
        ];
    }
}
