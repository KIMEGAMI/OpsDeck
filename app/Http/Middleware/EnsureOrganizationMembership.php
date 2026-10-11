<?php

namespace App\Http\Middleware;

use App\Enums\MembershipStatus;
use App\Models\Organization;
use App\Models\OrganizationMembership;
use App\Services\OrganizationActivityRecorder;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureOrganizationMembership
{
    public function __construct(private OrganizationActivityRecorder $activityRecorder) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user === null) {
            abort(Response::HTTP_UNAUTHORIZED);
        }

        $selectedOrganizationId = $request->session()->get('current_organization_id');
        $memberships = OrganizationMembership::query()
            ->with('organization')
            ->where('user_id', $user->id)
            ->where('status', MembershipStatus::Active->value);

        $membership = $selectedOrganizationId === null
            ? $memberships->first()
            : $memberships->where('organization_id', $selectedOrganizationId)->first();

        if ($membership === null) {
            abort(Response::HTTP_FORBIDDEN);
        }

        $request->session()->put('current_organization_id', $membership->organization_id);
        $request->attributes->set('currentOrganization', $membership->organization);
        $request->attributes->set('currentMembership', $membership);

        $response = $next($request);
        if ($response->isSuccessful() || $response->isRedirection()) {
            $this->recordActivity($membership->organization);
        }

        return $response;
    }

    private function recordActivity(Organization $organization): void
    {
        try {
            $this->activityRecorder->record($organization);
        } catch (QueryException) {
            // Activity metrics must not make an otherwise successful user request fail.
        }
    }
}
