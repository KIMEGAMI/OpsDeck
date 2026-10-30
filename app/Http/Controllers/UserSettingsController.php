<?php

namespace App\Http\Controllers;

use App\Http\Requests\Auth\GoogleAccountSettingsRequest;
use App\Http\Requests\UpdateUserPasswordRequest;
use App\Http\Requests\UpdateUserProfileRequest;
use App\Models\User;
use App\Services\GoogleLoginCallbackReadiness;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class UserSettingsController extends Controller
{
    public function edit(Request $request, GoogleLoginCallbackReadiness $googleLoginCallbackReadiness): View
    {
        /** @var User $user */
        $user = $request->user();
        $memberships = $user->activeOrganizationMemberships()
            ->with('organization:id,name')
            ->orderBy('organization_id')
            ->get();

        $googleLoginEnabled = config('services.google.login_enabled') === true;

        return view('settings.profile', [
            'user' => $user,
            'memberships' => $memberships,
            'googleLoginEnabled' => $googleLoginEnabled,
            'googleLoginAvailable' => $googleLoginEnabled && $googleLoginCallbackReadiness->matchesRequestUrl($request),
        ]);
    }

    public function updateProfile(UpdateUserProfileRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['name' => $request->validated('name')])->save();

        return to_route('settings.profile')->with('status', '表示名を更新しました。');
    }

    public function updatePassword(UpdateUserPasswordRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill([
            'password' => $request->validated('password'),
            'remember_token' => Str::random((int) config('opsdeck.auth.remember_token_length')),
        ])->save();
        $request->session()->regenerate();

        return to_route('settings.profile')->with('status', 'パスワードを変更しました。');
    }

    public function disconnectGoogleLogin(GoogleAccountSettingsRequest $request): RedirectResponse
    {
        /** @var User $user */
        $user = $request->user();
        $user->forceFill(['google_id' => null])->save();

        return to_route('settings.profile')->with('status', 'Googleアカウントの連携を解除しました。');
    }
}
