<?php

namespace App\Http\Requests\Requester;

use App\Models\Requester;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;

class RequesterStoreRequest extends FormRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:120', $this->notAlreadyListed()],
            'organization' => ['nullable', 'string', 'max:120'],
            'email' => ['nullable', 'email', 'max:255'],
        ];
    }

    /**
     * The whole point of a managed list is that one person appears once, so
     * the duplicate is answered on the field rather than by the unique index —
     * which compares the normalised name and would otherwise turn "budi " next
     * to "Budi" into a 500.
     */
    protected function notAlreadyListed(): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail): void {
            if (is_string($value) && Requester::isListed($value, $this->ignoredRequesterId())) {
                $fail('Pemohon dengan nama ini sudah terdaftar.');
            }
        };
    }

    /**
     * The row a rename is allowed to collide with: none, when adding one.
     */
    protected function ignoredRequesterId(): ?int
    {
        return null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama pemohon',
            'organization' => 'asal',
            'email' => 'email',
        ];
    }
}
