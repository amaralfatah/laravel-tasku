<?php

use App\Enums\WorkspaceRole;
use App\Models\OrgUnit;
use App\Models\Project;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use App\Services\TaskHierarchy;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Process;

/**
 * @return array{0: WorkspaceMember, 1: Project}
 */
function aiProject(WorkspaceRole $role = WorkspaceRole::Manager): array
{
    $workspace = Workspace::factory()->create();
    $unit = OrgUnit::factory()->rootOf($workspace)->create();
    $member = WorkspaceMember::factory()
        ->for($workspace)
        ->create(['role' => $role, 'org_unit_id' => $unit->id]);

    $project = Project::factory()->in($unit)->create();
    $project->members()->attach($member->user_id);

    return [$member, $project];
}

/**
 * Another project in the same workspace as a member, since a monitoring page
 * crosses projects but never crosses tenants.
 */
function aiSiblingProject(WorkspaceMember $member, bool $joined = true): Project
{
    $project = Project::factory()->in(OrgUnit::query()->findOrFail($member->org_unit_id))->create();

    if ($joined) {
        $project->members()->attach($member->user_id);
    }

    return $project;
}

/**
 * The CLI answers a JSON envelope with the model's text in `result`.
 *
 * @param  array<string, mixed>  $plan
 */
function fakeClaude(array $plan): void
{
    Process::fake([
        '*' => Process::result(json_encode([
            'type' => 'result',
            'is_error' => false,
            'result' => json_encode($plan),
        ])),
    ]);
}

test('a sentence is planned but nothing is written until the plan is applied', function () {
    [$member, $project] = aiProject();

    fakeClaude([
        'summary' => 'Membuat satu task.',
        'operations' => [
            ['op' => 'create', 'title' => 'Siapkan laporan', 'due_date' => '2026-10-01'],
        ],
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->postJson(route('tasks.ai.plan', $project), ['instruction' => 'buat task siapkan laporan', 'model' => 'claude'])
        ->assertOk()
        ->assertJsonPath('operations.0.title', 'Siapkan laporan');

    expect(Task::query()->count())->toBe(0);
});

test('applying a plan creates, updates and deletes tasks', function () {
    [$member, $project] = aiProject();

    $hierarchy = app(TaskHierarchy::class);
    $stale = $hierarchy->create($project, ['title' => 'Task lama']);
    $renamed = $hierarchy->create($project, ['title' => 'Salah judul']);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.ai.apply', $project), [
            'operations' => [
                ['op' => 'create', 'ref' => 'a', 'title' => 'Rilis v2', 'assignee_id' => $member->user_id],
                ['op' => 'create', 'parent_ref' => 'a', 'title' => 'Uji regresi'],
                ['op' => 'update', 'id' => $renamed->id, 'title' => 'Judul benar', 'status' => 'done'],
                ['op' => 'delete', 'id' => $stale->id],
            ],
        ])
        ->assertSessionHasNoErrors()
        ->assertRedirect();

    $parent = Task::query()->where('title', 'Rilis v2')->sole();
    $child = Task::query()->where('title', 'Uji regresi')->sole();

    expect($parent->assignee_id)->toBe($member->user_id)
        ->and($child->parent_task_id)->toBe($parent->id)
        ->and($renamed->refresh()->title)->toBe('Judul benar')
        // A leaf turned Done carries the percentage its status forces (TSK-15).
        ->and($renamed->progress)->toBe(100)
        ->and(Task::query()->find($stale->id))->toBeNull();
});

test('an operation naming somebody off the project is refused and takes the whole plan with it', function () {
    [$member, $project] = aiProject();

    $outsider = User::factory()->create();

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.ai.apply', $project), [
            'operations' => [
                ['op' => 'create', 'title' => 'Task pertama'],
                ['op' => 'create', 'title' => 'Task kedua', 'assignee_id' => $outsider->id],
            ],
        ])
        ->assertSessionHasErrors('operations');

    // The first operation is rolled back with the second, so a preview never
    // half happens.
    expect(Task::query()->count())->toBe(0);
});

test('a task belonging to another project may not be touched through a plan', function () {
    [$member, $project] = aiProject();
    [, $other] = aiProject();

    $foreign = app(TaskHierarchy::class)->create($other, ['title' => 'Bukan punya kita']);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('tasks.ai.apply', $project), [
            'operations' => [['op' => 'delete', 'id' => $foreign->id]],
        ])
        ->assertSessionHasErrors('operations');

    expect(Task::withoutGlobalScopes()->find($foreign->id))->not->toBeNull();
});

test('the CLI is handed the environment a web request does not carry', function () {
    [$member, $project] = aiProject();

    fakeClaude(['summary' => 'Tidak ada perubahan.', 'operations' => [['op' => 'create', 'title' => 'Apa saja']]]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->postJson(route('tasks.ai.plan', $project), ['instruction' => 'buat task', 'model' => 'claude'])
        ->assertOk();

    // Bun refuses every network request without SystemRoot on Windows, and the
    // CLI reads its sign in from the home directory — neither survives a
    // stripped web server environment unless it is forwarded.
    Process::assertRan(fn ($process): bool => windows_os()
        ? ($process->environment['SystemRoot'] ?? '') !== ''
        : is_array($process->environment));
});

test('a greeting is answered, not rejected', function () {
    [$member, $project] = aiProject();

    fakeClaude([
        'summary' => 'Perintah cuma sapaan, tidak ada instruksi task jelas.',
        'operations' => [],
    ]);

    // Nothing to do is an answer the chat can show. Making it a 422 put a
    // failed request in the browser console for an ordinary "hi".
    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->postJson(route('tasks.ai.plan', $project), ['instruction' => 'hi', 'model' => 'claude'])
        ->assertOk()
        ->assertJsonPath('operations', [])
        ->assertJsonPath('summary', 'Perintah cuma sapaan, tidak ada instruksi task jelas.');
});

test('a failing CLI is reported on the field rather than as a server error', function () {
    [$member, $project] = aiProject();

    Process::fake(['*' => Process::result(errorOutput: 'claude: command not found', exitCode: 1)]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->postJson(route('tasks.ai.plan', $project), ['instruction' => 'buat task apa saja', 'model' => 'claude'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('instruction');
});

test('the model the dialog picked is the one that plans', function () {
    [$member, $project] = aiProject();

    config()->set([
        'ai.models.gemini.enabled' => true,
        'ai.models.gemini.api_key' => 'kunci-uji',
        'ai.models.gemini.model' => 'gemini-2.5-flash',
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode([
                    'summary' => 'Membuat satu task.',
                    'operations' => [['op' => 'create', 'title' => 'Rapat mingguan']],
                ])]]]],
            ],
        ]),
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->postJson(route('tasks.ai.plan', $project), ['instruction' => 'buat task rapat mingguan', 'model' => 'gemini'])
        ->assertOk()
        ->assertJsonPath('operations.0.title', 'Rapat mingguan');

    Http::assertSent(fn ($request): bool => $request->hasHeader('x-goog-api-key', 'kunci-uji')
        && str_contains($request->url(), 'gemini-2.5-flash:generateContent')
        && $request['generationConfig']['responseMimeType'] === 'application/json');

    // Choosing Gemini leaves the CLI alone entirely.
    Process::assertNothingRan();
});

test('earlier turns are handed to the model so a follow up has something to refer to', function () {
    [$member, $project] = aiProject();

    config()->set([
        'ai.models.gemini.enabled' => true,
        'ai.models.gemini.api_key' => 'kunci-uji',
    ]);

    // Gemini carries the prompt in the request body, which is where this can
    // be read back; the CLI hands it over on stdin.
    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode([
                    'summary' => 'Mengubah deadline.',
                    'operations' => [['op' => 'create', 'title' => 'Rilis v2']],
                ])]]]],
            ],
        ]),
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->postJson(route('tasks.ai.plan', $project), [
            'instruction' => 'ganti deadline yang tadi jadi Senin',
            'model' => 'gemini',
            'history' => [
                ['role' => 'user', 'text' => 'buat task rilis v2'],
                ['role' => 'assistant', 'text' => 'Membuat task Rilis v2.'],
            ],
        ])
        ->assertOk();

    Http::assertSent(function ($request): bool {
        $prompt = (string) $request['contents'][0]['parts'][0]['text'];

        return str_contains($prompt, 'Percakapan sebelumnya:')
            && str_contains($prompt, 'Pengguna: buat task rilis v2')
            && str_contains($prompt, 'Asisten: Membuat task Rilis v2.')
            && str_contains($prompt, 'ganti deadline yang tadi jadi Senin');
    });
});

test('on a monitoring page the conversation is one person\'s work across projects', function () {
    [$member, $project] = aiProject();

    // A second project the same person carries work in.
    $other = aiSiblingProject($member);

    $hierarchy = app(TaskHierarchy::class);
    $mine = $hierarchy->create($project, ['title' => 'Punya saya', 'assignee_id' => $member->user_id]);
    $elsewhere = $hierarchy->create($other, ['title' => 'Di project lain', 'assignee_id' => $member->user_id]);
    $notMine = $hierarchy->create($project, ['title' => 'Punya orang lain']);

    config()->set([
        'ai.models.gemini.enabled' => true,
        'ai.models.gemini.api_key' => 'kunci-uji',
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response([
            'candidates' => [
                ['content' => ['parts' => [['text' => json_encode([
                    'summary' => 'Menandai selesai.',
                    'operations' => [['op' => 'update', 'id' => $elsewhere->id, 'status' => 'done']],
                ])]]]],
            ],
        ]),
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->postJson(route('monitoring.ai.plan', $member), [
            'instruction' => 'tandai selesai task di project lain',
            'model' => 'gemini',
        ])
        ->assertOk();

    Http::assertSent(function ($request) use ($mine, $elsewhere, $notMine): bool {
        $prompt = (string) $request['contents'][0]['parts'][0]['text'];

        // This person's work, wherever it sits — and nobody else's. Matched on
        // the reference rather than the id, which a member row could share.
        return str_contains($prompt, $mine->title)
            && str_contains($prompt, $elsewhere->title)
            && ! str_contains($prompt, $notMine->title);
    });
});

test('a monitoring plan creates in the project it names and refuses one it may not', function () {
    [$member, $project] = aiProject();

    // Same workspace, but not a project this viewer is on.
    $stranger = aiSiblingProject($member, joined: false);

    app(TaskHierarchy::class)->create($project, [
        'title' => 'Sudah ada',
        'assignee_id' => $member->user_id,
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('monitoring.ai.apply', $member), [
            'operations' => [
                ['op' => 'create', 'project_id' => $project->id, 'title' => 'Task baru'],
            ],
        ])
        ->assertSessionHasNoErrors();

    expect(Task::query()->where('title', 'Task baru')->sole()->project_id)->toBe($project->id);

    // A project this person's work never touches, and the viewer is not on.
    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('monitoring.ai.apply', $member), [
            'operations' => [
                ['op' => 'create', 'project_id' => $stranger->id, 'title' => 'Menyelinap'],
            ],
        ])
        ->assertSessionHasErrors('operations');

    expect(Task::withoutGlobalScopes()->where('title', 'Menyelinap')->exists())->toBeFalse();
});

test('a monitoring plan may not touch a task belonging to somebody else', function () {
    [$member, $project] = aiProject();

    $someoneElses = app(TaskHierarchy::class)->create($project, ['title' => 'Bukan tanggung jawabnya']);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->post(route('monitoring.ai.apply', $member), [
            'operations' => [['op' => 'delete', 'id' => $someoneElses->id]],
        ])
        ->assertSessionHasErrors('operations');

    expect(Task::query()->find($someoneElses->id))->not->toBeNull();
});

test('a model this deployment does not offer is refused', function () {
    [$member, $project] = aiProject();

    // A Vercel-shaped deployment: no CLI, Gemini only.
    config()->set([
        'ai.models.claude.enabled' => false,
        'ai.models.gemini.enabled' => true,
        'ai.models.gemini.api_key' => 'kunci-uji',
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->postJson(route('tasks.ai.plan', $project), ['instruction' => 'buat task', 'model' => 'claude'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('model');

    Process::assertNothingRan();
});

test('a rejected gemini key is reported on the field', function () {
    [$member, $project] = aiProject();

    config()->set([
        'ai.models.gemini.enabled' => true,
        'ai.models.gemini.api_key' => 'kunci-salah',
    ]);

    Http::fake([
        'generativelanguage.googleapis.com/*' => Http::response(
            ['error' => ['message' => 'API key not valid.']],
            400,
        ),
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->postJson(route('tasks.ai.plan', $project), ['instruction' => 'buat task', 'model' => 'gemini'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('instruction');
});

test('the assistant is not reachable where no model can run', function () {
    [$member, $project] = aiProject();

    // A production deployment with no Gemini key: no CLI on the runtime, and
    // nothing to call over HTTP either.
    config()->set([
        'ai.models.claude.enabled' => false,
        'ai.models.gemini.enabled' => false,
    ]);

    $this->actingAs($member->user)
        ->withSession(['workspace_id' => $member->workspace_id])
        ->postJson(route('tasks.ai.plan', $project), ['instruction' => 'buat task', 'model' => 'gemini'])
        ->assertNotFound();
});
