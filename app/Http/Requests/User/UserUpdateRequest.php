<?php

namespace App\Http\Requests\User;

use App\Models\User;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Validator;

/**
 * Profile and activation, as the operator edits them.
 *
 * `is_super_admin` is deliberately absent. Promoting an operator stays with
 * `tasku:super-admin`: it is the one right that can lock the platform's
 * operators out of their own console, and a console button is too easy a way
 * to do that by accident.
 */
class UserUpdateRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            'email' => [
                'sometimes',
                'required',
                'string',
                'email',
                'max:255',
                Rule::unique('users', 'email')->ignore($this->targetUser()->id),
            ],
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator): void {
            // Switching your own account off would end the session that is
            // doing the switching, and nobody else can turn it back on.
            if (
                $this->boolean('is_active') === false
                && $this->has('is_active')
                && $this->targetUser()->is($this->user())
            ) {
                $validator->errors()->add('is_active', 'Anda tidak bisa menonaktifkan akun Anda sendiri.');
            }
        });
    }

    /**
     * The account being edited, as bound to the route.
     */
    protected function targetUser(): User
    {
        /** @var User $user */
        $user = $this->route('user');

        return $user;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama',
            'email' => 'email',
            'is_active' => 'status akun',
        ];
    }
}
