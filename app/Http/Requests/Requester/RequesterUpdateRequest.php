<?php

namespace App\Http\Requests\Requester;

use App\Models\Requester;
use Illuminate\Contracts\Validation\ValidationRule;

class RequesterUpdateRequest extends RequesterStoreRequest
{
    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'name' => ['sometimes', 'required', 'string', 'max:120', $this->notAlreadyListed()],
            'organization' => ['sometimes', 'nullable', 'string', 'max:120'],
            'email' => ['sometimes', 'nullable', 'email', 'max:255'],
            // Retiring a requester rather than deleting them, which is what
            // anyone with tasks against their name gets.
            'is_active' => ['sometimes', 'boolean'],
        ];
    }

    protected function ignoredRequesterId(): ?int
    {
        $requester = $this->route('requester');

        return $requester instanceof Requester ? $requester->id : null;
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            ...parent::attributes(),
            'is_active' => 'status',
        ];
    }
}
