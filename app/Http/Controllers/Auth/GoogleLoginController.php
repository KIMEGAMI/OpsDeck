<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\GoogleAccountSettingsRequest;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GoogleLoginController extends Controller
{
    private const GOOGLE_STATE_SESSION_KEY = 'google_login_oauth_state';

    private const GOOGLE_STATE_LENGTH = 64;

    private const GOOGLE_SUBJECT_MAX_LENGTH = 255;

    public function redirect(Request $request): RedirectResponse
    {
        return $this->beginGoogleFlow($request, 'login');
    }

    public function connect(GoogleAccountSettingsRequest $request): RedirectResponse
    {
        return $this->beginGoogleFlow($request, 'link');
    }

    public function callback(Request $request): RedirectResponse
    {
        $oauthState = $request->session()->pull(self::GOOGLE_STATE_SESSION_KEY);
        $expectedState = is_array($oauthState) ? ($oauthState['value'] ?? null) : null;
        $receivedState = $request->query('state');
        $flowMode = is_array($oauthState) && is_string($oauthState['mode'] ?? null)
            ? $oauthState['mode']
            : '';

        abort_unless(
            is_string($expectedState)
                && is_string($receivedState)
                && hash_equals($expectedState, $receivedState)
                && in_array($flowMode, ['login', 'link'], true),
            419,
            'Googleログインの状態を確認できませんでした。もう一度お試しください。',
        );

        $user = null;
        if ($flowMode === 'link') {
            $user = $request->user();
            abort_unless(
                $user instanceof User
                    && (int) ($oauthState['user_id'] ?? 0) === (int) $user->getKey()
                    && $user->hasVerifiedEmail(),
                403,
            );
        } elseif ($request->user() !== null) {
            return to_route('dashboard');
        }

        if ($request->filled('error')) {
            return to_route($this->flowRoute($flowMode))->with('status', 'Googleログインをキャンセルしました。');
        }

        abort_unless(config('services.google.login_enabled'), 503, 'Googleログインが設定されていません。');

        $authorizationCode = $request->query('code');
        abort_unless(is_string($authorizationCode) && trim($authorizationCode) !== '', 422, 'Googleの認証コードがありません。');

        try {
            $tokenResponse = Http::asForm()
                ->acceptJson()
                ->timeout(config('services.google.timeout_seconds'))
                ->post(config('services.google.token_endpoint'), [
                    'code' => $authorizationCode,
                    'client_id' => config('services.google.client_id'),
                    'client_secret' => config('services.google.client_secret'),
                    'redirect_uri' => config('services.google.login_redirect_uri'),
                    'grant_type' => 'authorization_code',
                ]);
        } catch (ConnectionException) {
            return $this->oauthRequestFailed($flowMode);
        }

        $accessToken = $tokenResponse->json('access_token');
        if (! $tokenResponse->successful() || ! is_string($accessToken) || $accessToken === '') {
            return $this->oauthRequestFailed($flowMode, $tokenResponse->status());
        }

        try {
            $userInfoResponse = Http::withToken($accessToken)
                ->acceptJson()
                ->timeout(config('services.google.timeout_seconds'))
                ->get(config('services.google.userinfo_endpoint'));
        } catch (ConnectionException) {
            return $this->oauthRequestFailed($flowMode);
        }

        $googleSubject = $userInfoResponse->json('sub');
        if (! $userInfoResponse->successful()
            || ! is_string($googleSubject)
            || $googleSubject === ''
            || strlen($googleSubject) > self::GOOGLE_SUBJECT_MAX_LENGTH) {
            return $this->oauthRequestFailed($flowMode, $userInfoResponse->status());
        }

        if ($flowMode === 'link' && $user !== null) {
            if (! $this->linkGoogleAccount($user, $googleSubject)) {
                return to_route('settings.profile')->with(
                    'status',
                    'このGoogleアカウントは別のOpsDeckアカウントに連携されているか、別のGoogleアカウントがすでに連携されています。',
                );
            }

            return to_route('settings.profile')->with('status', 'Googleアカウントを連携しました。次回からGoogleでログインできます。');
        }

        $user = User::query()->where('google_id', $googleSubject)->first();
        if ($user === null || ! $user->hasVerifiedEmail()) {
            return to_route('login')->with(
                'status',
                '初回利用時はメールアドレスとパスワードでログインし、個人設定からGoogleアカウントを連携してください。',
            );
        }

        Auth::guard('web')->login($user);
        $request->session()->regenerate();

        return redirect()->intended(route('dashboard'));
    }

    private function beginGoogleFlow(Request $request, string $flowMode): RedirectResponse
    {
        abort_unless(config('services.google.login_enabled'), 503, 'Googleログインが設定されていません。');

        $state = Str::random(self::GOOGLE_STATE_LENGTH);
        $stateData = [
            'value' => $state,
            'mode' => $flowMode,
        ];
        if ($flowMode === 'link') {
            $user = $request->user();
            abort_unless($user instanceof User, 403);
            $stateData['user_id'] = $user->getKey();
        }
        $request->session()->put(self::GOOGLE_STATE_SESSION_KEY, $stateData);

        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.login_redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('services.google.login_scopes', [])),
            'state' => $state,
            'prompt' => 'select_account',
        ]);

        return redirect()->away(config('services.google.authorization_endpoint').'?'.$query);
    }

    private function linkGoogleAccount(User $user, string $googleSubject): bool
    {
        try {
            return DB::transaction(function () use ($user, $googleSubject): bool {
                $currentUser = User::query()->lockForUpdate()->findOrFail($user->getKey());
                $linkedUser = User::query()->where('google_id', $googleSubject)->lockForUpdate()->first();

                if (($linkedUser !== null && ! $linkedUser->is($currentUser))
                    || ($currentUser->google_id !== null && ! hash_equals((string) $currentUser->google_id, $googleSubject))) {
                    return false;
                }

                $currentUser->forceFill(['google_id' => $googleSubject])->save();

                return true;
            });
        } catch (QueryException $exception) {
            $linkedUser = User::query()->where('google_id', $googleSubject)->first();
            if ($linkedUser === null) {
                throw $exception;
            }

            return $linkedUser->is($user);
        }
    }

    private function flowRoute(string $flowMode): string
    {
        return $flowMode === 'link' ? 'settings.profile' : 'login';
    }

    private function oauthRequestFailed(string $flowMode, ?int $responseStatus = null): RedirectResponse
    {
        $errorReference = (string) Str::uuid();

        Log::warning('Google login OAuth request failed.', [
            'error_reference' => $errorReference,
            'response_status' => $responseStatus,
        ]);

        return to_route($this->flowRoute($flowMode))->with(
            'status',
            'Googleログインを完了できませんでした。時間をおいて再度お試しください。（問い合わせコード: '.$errorReference.'）',
        );
    }
}
