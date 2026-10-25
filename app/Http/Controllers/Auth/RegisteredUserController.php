<?php

namespace App\Http\Controllers\Auth;

use App\Enums\MembershipRole;
use App\Enums\MembershipStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\RegisterRequest;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Models\ReferralCode;
use App\Models\ReferralConversion;
use App\Models\User;
use App\Services\ProductEventRecorder;
use Illuminate\Auth\Events\Registered;
use Illuminate\Http\RedirectResponse;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\View\View;

class RegisteredUserController extends Controller
{
    public function create(): View
    {
        return view('auth.register');
    }

    public function store(RegisterRequest $request): RedirectResponse
    {
        $referralCode = config('opsdeck.release_features.referrals')
            ? ReferralCode::query()->where('code', $request->session()->get('referral_code'))->where('active', true)->first()
            : null;
        [$user, $organization] = DB::transaction(function () use ($request, $referralCode): array {
            $validated = $request->validated();
            $user = User::query()->create([
                'name' => $validated['name'],
                'email' => $validated['email'],
                'password' => $validated['password'],
            ]);
            $user->forceFill(['email_verification_required_at' => now()])->save();

            $organization = Organization::query()->create([
                'name' => $validated['organization_name'],
                'slug' => $this->uniqueSlug($validated['organization_name']),
                'company_size' => $validated['company_size'] ?? null,
                'industry' => $validated['industry'] ?? null,
                'timezone' => $validated['timezone'] ?? config('opsdeck.default_timezone'),
                'plan_code' => config('opsdeck.default_plan_code'),
                'billing_status' => config('opsdeck.default_billing_status'),
            ]);

            OrganizationMembership::query()->create([
                'organization_id' => $organization->id,
                'user_id' => $user->id,
                'role' => MembershipRole::Owner,
                'status' => MembershipStatus::Active,
                'joined_at' => now(),
            ]);

            if ($referralCode !== null) {
                ReferralConversion::query()->create(['referral_code_id' => $referralCode->id, 'organization_id' => $organization->id]);
            }

            return [$user, $organization];
        });

        event(new Registered($user));
        Auth::login($user);
        $request->session()->regenerate();
        $request->session()->put('current_organization_id', $organization->id);
        app(ProductEventRecorder::class)->record('registered', $organization);
        if ($referralCode !== null) {
            app(ProductEventRecorder::class)->record('referral_registered', $organization);
        }
        $request->session()->forget('referral_code');

        return to_route('onboarding.index');
    }

    private function uniqueSlug(string $organizationName): string
    {
        $baseSlug = Str::slug($organizationName);
        $baseSlug = $baseSlug !== '' ? $baseSlug : Str::random(config('opsdeck.slug_suffix_length'));

        do {
            $slug = $baseSlug.'-'.Str::lower(Str::random(config('opsdeck.slug_suffix_length')));
        } while (Organization::query()->where('slug', $slug)->exists());

        return $slug;
    }
}
