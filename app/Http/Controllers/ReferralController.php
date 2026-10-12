<?php

namespace App\Http\Controllers;

use App\Models\ReferralCode;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\View;

class ReferralController extends Controller
{
    public function show(Request $request): View
    {
        $organization = $request->attributes->get('currentOrganization');
        abort_unless($request->user()?->can('export', $organization) === true, 403);
        $referral = ReferralCode::query()->firstOrCreate(['organization_id' => $organization->id], ['code' => Str::lower(Str::random(16)), 'active' => true]);
        $referral->loadCount('conversions');

        return view('referrals.show', compact('organization', 'referral'));
    }

    public function redirect(string $code): RedirectResponse
    {
        $referral = ReferralCode::query()->where('code', $code)->where('active', true)->firstOrFail();
        $referral->increment('click_count');
        $referral->update(['last_clicked_at' => now()]);

        return to_route('register')->with('referral_code', $referral->code);
    }
}
