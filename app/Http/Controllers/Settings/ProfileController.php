<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Http\Requests\Settings\ProfileDeleteRequest;
use App\Http\Requests\Settings\ProfileUpdateRequest;
use App\Models\User;
use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Inertia\Inertia;
use Inertia\Response;

class ProfileController extends Controller
{
    /**
     * Show the user's profile settings page.
     */
    public function edit(Request $request): Response
    {
        return Inertia::render('settings/profile', [
            'mustVerifyEmail' => $request->user() instanceof MustVerifyEmail,
            'status' => $request->session()->get('status'),
        ]);
    }

    /**
     * Update the user's profile information.
     */
    public function update(ProfileUpdateRequest $request): RedirectResponse
    {
        $user = $request->user();

        $user->fill($request->safe()->only(['name', 'email']));

        if ($user->isDirty('email')) {
            $user->email_verified_at = null;
        }

        $this->syncAvatar($request, $user);

        $user->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Profil diperbarui.']);

        return to_route('profile.edit');
    }

    /**
     * Store a newly uploaded avatar, or clear the current one.
     */
    protected function syncAvatar(ProfileUpdateRequest $request, User $user): void
    {
        $upload = $request->file('avatar');
        $shouldRemove = $request->boolean('remove_avatar');

        if ($upload === null && ! $shouldRemove) {
            return;
        }

        if ($user->avatar_path !== null) {
            Storage::disk('public')->delete($user->avatar_path);
        }

        $user->avatar_path = $upload?->store('avatars', 'public');
    }

    /**
     * Delete the user's profile.
     */
    public function destroy(ProfileDeleteRequest $request): RedirectResponse
    {
        $user = $request->user();

        Auth::logout();

        $user->delete();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect('/');
    }
}
