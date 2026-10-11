<?php

namespace App\Http\Controllers;

use App\Http\Requests\GoogleSheet\CallbackGoogleSheetRequest;
use App\Models\GoogleSheetConnection;
use App\Models\Organization;
use App\Services\PlanFeatureService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\View\View;

class GoogleSheetConnectionController extends Controller
{
    public function show(Request $request): View
    {
        $organization = $this->organization($request);
        $this->authorize('view', $organization);
        $connection = GoogleSheetConnection::query()->where('organization_id', $organization->id)->first();
        $featureAvailable = app(PlanFeatureService::class)->isAvailable($organization, 'google_sheets');

        return view('google-sheets.connection', compact('organization', 'connection', 'featureAvailable'));
    }

    public function connect(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        app(PlanFeatureService::class)->assertAvailable($organization, 'google_sheets', 'Google Sheets連携はPremiumプランで利用できます。');
        abort_unless(config('services.google.client_id') && config('services.google.redirect_uri'), 503, 'Google Sheets連携が設定されていません。');
        $state = Str::random(64);
        $request->session()->put('google_sheets_oauth_state', ['value' => $state, 'organization_id' => $organization->id]);
        $query = http_build_query([
            'client_id' => config('services.google.client_id'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'response_type' => 'code',
            'scope' => implode(' ', config('services.google.scopes', [])),
            'access_type' => 'offline',
            'prompt' => 'consent',
            'state' => $state,
        ]);

        return redirect()->away(config('services.google.authorization_endpoint').'?'.$query);
    }

    public function callback(CallbackGoogleSheetRequest $request): RedirectResponse
    {
        $oauthState = $request->session()->pull('google_sheets_oauth_state');
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        app(PlanFeatureService::class)->assertAvailable($organization, 'google_sheets', 'Google Sheets連携はPremiumプランで利用できます。');
        abort_unless(is_array($oauthState) && hash_equals((string) ($oauthState['value'] ?? ''), (string) $request->string('state')), 419, 'OAuth stateが無効です。');
        abort_unless((int) ($oauthState['organization_id'] ?? 0) === $organization->id, 403);
        if ($request->filled('error')) {
            return to_route('google-sheets.connection')->with('status', 'Google Sheets連携をキャンセルしました。');
        }
        abort_unless($request->filled('code'), 422, 'Google OAuth認証コードがありません。');

        $response = Http::asForm()->timeout(10)->post(config('services.google.token_endpoint'), [
            'code' => $request->string('code')->toString(),
            'client_id' => config('services.google.client_id'),
            'client_secret' => config('services.google.client_secret'),
            'redirect_uri' => config('services.google.redirect_uri'),
            'grant_type' => 'authorization_code',
        ]);
        abort_unless($response->successful() && is_string($response->json('access_token')), 422, 'Google OAuthトークンを取得できませんでした。');
        $expiresIn = max(0, (int) $response->json('expires_in', 3600));
        $connection = GoogleSheetConnection::query()->where('organization_id', $organization->id)->first();
        GoogleSheetConnection::query()->updateOrCreate(
            ['organization_id' => $organization->id],
            [
                'connected_by' => $request->user()->id,
                'access_token' => $response->json('access_token'),
                'refresh_token' => $response->json('refresh_token') ?: $connection?->refresh_token,
                'token_expires_at' => now()->addSeconds($expiresIn),
                'scopes_json' => config('services.google.scopes', []),
                'revoked_at' => null,
            ],
        );

        return to_route('google-sheets.connection')->with('status', 'Google Sheetsを接続しました。');
    }

    public function disconnect(Request $request): RedirectResponse
    {
        $organization = $this->organization($request);
        $this->authorize('manageOperationalData', $organization);
        $connection = GoogleSheetConnection::query()->where('organization_id', $organization->id)->first();
        if ($connection !== null) {
            $connection->update(['access_token' => null, 'refresh_token' => null, 'revoked_at' => now()]);
        }

        return to_route('google-sheets.connection')->with('status', 'Google Sheets連携を解除しました。');
    }

    private function organization(Request $request): Organization
    {
        /** @var Organization $organization */
        $organization = $request->attributes->get('currentOrganization');

        return $organization;
    }
}
