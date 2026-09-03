<?php

namespace App\Http\Requests\Project;

use App\Concerns\ScopesValidationToWorkspace;
use App\Enums\ProjectStatus;
use App\Models\Project;
use Illuminate\Contracts\Validation\ValidationRule;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ProjectStoreRequest extends FormRequest
{
    use ScopesValidationToWorkspace;

    /**
     * Checked before the rules run: someone who may not start a project at
     * all should be refused, not told which fields their attempt was missing.
     */
    public function authorize(): bool
    {
        return (bool) $this->user()?->can('create', Project::class);
    }

    /**
     * Keys are stored upper case, so a lower case one typed by hand is the
     * same key rather than a validation error.
     */
    protected function prepareForValidation(): void
    {
        if ($this->has('key')) {
            $key = strtoupper(trim((string) $this->input('key')));

            $this->merge(['key' => $key === '' ? null : $key]);
        }
    }

    /**
     * @return array<string, ValidationRule|array<mixed>|string>
     */
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            // Left blank the server derives one from the name, the way Jira
            // prefills the field.
            'key' => [
                'nullable',
                'string',
                'min:2',
                'max:'.Project::KEY_MAX_LENGTH,
                'regex:/^[A-Z][A-Z0-9]*$/',
                $this->uniqueInWorkspace('projects', 'key'),
            ],
            'description' => ['nullable', 'string', 'max:5000'],
            'org_unit_id' => ['required', 'integer', $this->existsAsOrgUnit()],
            'status' => ['sometimes', Rule::enum(ProjectStatus::class)],
            'member_ids' => ['sometimes', 'array'],
            'member_ids.*' => ['integer', $this->existsAsWorkspaceMember()],
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
            'member_ids' => 'anggota project',
        ];
    }
}
