<?php

namespace App\Http\Controllers;

use App\Actions\ChangeMemberRole;
use App\Enums\WorkspaceRole;
use App\Http\Requests\User\UserPasswordRequest;
use App\Http\Requests\User\UserStoreRequest;
use App\Http\Requests\User\UserUpdateRequest;
use App\Models\User;
use App\Models\Workspace;
use App\Models\WorkspaceMember;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

/**
 * Account roster for the platform super admin (SA-5).
 *
 * The operator console's second half. `WorkspaceController` hands out the
 * entities; this hands out the people who work in them — creating accounts,
 * repairing the ones that lock themselves out, and cutting access without
 * erasing anybody's trail through the tasks.
 *
 * Carries no tenant context, exactly like its sibling: a super admin never
 * enters a workspace (SA-4), so `WorkspaceMember` queries here pass
 * `withoutGlobalScopes()` and name their `workspace_id` by hand.
 */
class UserController extends Controller
{
    /**
     * How many accounts a page of the roster shows.
     */
    protected const PER_PAGE = 20;

    public function index(Request $request): Response
    {
        $search = trim((string) $request->query('search', ''));
        $status = $request->string('status')->toString();

        $users = User::query()
            ->when($search !== '', function (Builder $query) use ($search): void {
                $term = '%'.mb_strtolower($search).'%';

                $query->where(function (Builder $inner) use ($term): void {
                    $inner->whereRaw('lower(name) like ?', [$term])
                        ->orWhereRaw('lower(email) like ?', [$term]);
                });
            })
            ->when($status === 'active', fn (Builder $query) => $query->where('is_active', true))
            ->when($status === 'inactive', fn (Builder $query) => $query->where('is_active', false))
            ->when($status === 'unassigned', fn (Builder $query) => $query
                ->where('is_super_admin', false)
                ->whereDoesntHave('workspaceMembers'))
            ->withCount(['workspaceMembers' => fn (Builder $query) => $query->withoutGlobalScopes()])
            ->orderBy('name')
            ->paginate(self::PER_PAGE)
            ->withQueryString()
            ->through(fn (User $user): array => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'is_super_admin' => $user->is_super_admin,
                'is_active' => $user->is_active,
                'is_self' => $user->is($request->user()),
                'workspaces_count' => $user->workspace_members_count,
                'created_at' => $user->created_at?->toDateString(),
            ]);

        return Inertia::render('users/index', [
            'users' => $users,
            'filters' => ['search' => $search, 'status' => $status],
            'stats' => $this->stats(),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Headline counts, so the operator reads the state of the platform without
     * paging through the table.
     *
     * @return array{total: int, active: int, inactive: int, unassigned: int}
     */
    protected function stats(): array
    {
        $active = User::query()->where('is_active', true)->count();
        $inactive = User::query()->where('is_active', false)->count();

        return [
            'total' => $active + $inactive,
            'active' => $active,
            'inactive' => $inactive,
            // Accounts that belong nowhere: invited and never placed, or left
            // behind when their last workspace let them go.
            'unassigned' => User::query()
                ->where('is_super_admin', false)
                ->whereDoesntHave('workspaceMembers')
                ->count(),
        ];
    }

    /**
     * One account, with every workspace it belongs to (SA-6).
     */
    public function show(User $user): Response
    {
        $memberships = WorkspaceMember::withoutGlobalScopes()
            ->with(['workspace:id,name,slug,is_active', 'orgUnit:id,name'])
            ->where('user_id', $user->id)
            ->get();

        $joinedWorkspaceIds = $memberships->pluck('workspace_id')->all();

        return Inertia::render('users/show', [
            'user' => [
                'id' => $user->id,
                'name' => $user->name,
                'email' => $user->email,
                'avatar' => $user->avatar,
                'is_super_admin' => $user->is_super_admin,
                'is_active' => $user->is_active,
                'is_self' => $user->is(request()->user()),
                'has_two_factor' => $user->two_factor_confirmed_at !== null,
                'created_at' => $user->created_at?->toDateString(),
            ],
            'memberships' => $memberships
                ->sortBy(fn (WorkspaceMember $member): string => (string) $member->workspace?->name)
                ->values()
                ->map(fn (WorkspaceMember $member): array => [
                    'id' => $member->id,
                    'workspace' => [
                        'id' => $member->workspace->id,
                        'name' => $member->workspace->name,
                        'is_active' => $member->workspace->is_active,
                    ],
                    'role' => $member->role->value,
                    'role_label' => $member->role->label(),
                    'role_code' => $member->role->code(),
                    'title' => $member->positionTitle(),
                    'org_unit' => $member->orgUnit?->only(['id', 'name']),
                    'joined_at' => $member->joined_at?->toDateString(),
                    // Marked rather than hidden: the operator should see why
                    // the role is stuck before they try to move it.
                    'is_last_top_role' => ChangeMemberRole::isLastTopRole($member),
                ])
                ->all(),
            // Only workspaces the account is not already in; a super admin
            // holds no membership at all, so their list is deliberately empty.
            'workspaceOptions' => $user->is_super_admin ? [] : Workspace::query()
                ->whereNotIn('id', $joinedWorkspaceIds)
                ->orderBy('name')
                ->get(['id', 'name', 'is_active'])
                ->map(fn (Workspace $workspace): array => [
                    'id' => $workspace->id,
                    'name' => $workspace->name,
                    'is_active' => $workspace->is_active,
                ])
                ->all(),
            'roles' => array_map(
                fn (WorkspaceRole $role): array => [
                    'value' => $role->value,
                    'label' => $role->label(),
                    'code' => $role->code(),
                    'description' => $role->description(),
                ],
                WorkspaceRole::cases(),
            ),
            'passwordRules' => Password::defaults()->toPasswordRulesString(),
        ]);
    }

    /**
     * Create an account outright (SA-5).
     *
     * Marked verified on creation: the operator typed the address, and an
     * account that cannot pass verification is one more thing for them to
     * repair by hand.
     */
    public function store(UserStoreRequest $request): RedirectResponse
    {
        $user = User::create($request->safe()->only(['name', 'email', 'password']));

        $user->forceFill(['email_verified_at' => now()])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => "Akun {$user->email} dibuat.",
        ]);

        return to_route('users.show', $user);
    }

    /**
     * Rename, re-address, or switch an account off.
     */
    public function update(UserUpdateRequest $request, User $user): RedirectResponse
    {
        $data = $request->validated();

        $user->fill($request->safe()->only(['name', 'email']));

        if (array_key_exists('is_active', $data)) {
            $user->is_active = (bool) $data['is_active'];
        }

        $wasDeactivated = $user->isDirty('is_active') && ! $user->is_active;

        $user->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $wasDeactivated
                ? "{$user->name} dinonaktifkan dan sesinya diakhiri."
                : 'Akun diperbarui.',
        ]);

        return back();
    }

    /**
     * Set a new password for somebody who cannot get in.
     */
    public function password(UserPasswordRequest $request, User $user): RedirectResponse
    {
        $user->forceFill([
            'password' => Hash::make($request->validated('password')),
            'remember_token' => null,
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Kata sandi disetel ulang.',
        ]);

        return back();
    }

    /**
     * Clear a two factor setup the person can no longer satisfy — a lost
     * phone, an authenticator wiped with the device.
     */
    public function destroyTwoFactor(User $user): RedirectResponse
    {
        $user->forceFill([
            'two_factor_secret' => null,
            'two_factor_recovery_codes' => null,
            'two_factor_confirmed_at' => null,
        ])->save();

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => 'Autentikasi dua faktor dilepas.',
        ]);

        return back();
    }
}
