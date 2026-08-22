<?php

namespace App\Http\Requests\Project;

use App\Concerns\ScopesValidationToWorkspace;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectUpdateRequest extends FormRequest
{
    use ScopesValidationToWorkspace;

    /**
     * Keys are stored upper case, so a lower case one typed by hand is the
     * same key rather than a validation error.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('key')) {
            $this->merge(['key' => strtoupper(trim((string) $this->input('key')))]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'required', 'string', 'max:255'],
            // Unlike creation the key is never derived here: a project already
            // has one, and blanking the field would silently renumber every
            // task reference.
            'key' => [
                'sometimes',
                'required',
                'string',
                'min:2',
                'max:'.Project::KEY_MAX_LENGTH,
                'regex:/^[A-Z][A-Z0-9]*$/',
                $this->uniqueInWorkspace('projects', 'key')->ignore($this->route('project')),
            ],
            'description' => ['sometimes', 'nullable', 'string', 'max:5000'],
            'org_unit_id' => ['sometimes', 'required', 'integer', $this->existsAsOrgUnit()],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'key.regex' => 'Key hanya boleh huruf kapital dan angka, diawali huruf.',
            'key.unique' => 'Key ini sudah dipakai project lain.',
        ];
    }

    /**
     * @return array<string, string>
     */
    public function attributes(): array
    {
        return [
            'name' => 'nama project',
            'key' => 'key project',
            'description' => 'deskripsi',
            'org_unit_id' => 'unit',
            'status' => 'status',
        ];
    }
}
