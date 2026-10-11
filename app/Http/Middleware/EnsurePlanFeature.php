<?php

namespace App\Http\Middleware;

use App\Models\Organization;
use App\Services\PlanFeatureService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsurePlanFeature
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next, string $feature): Response
    {
        $organization = $request->attributes->get('currentOrganization');
        abort_unless($organization instanceof Organization, Response::HTTP_FORBIDDEN);
        app(PlanFeatureService::class)->assertAvailable($organization, $feature, 'この機能はPremiumプランで利用できます。契約プランをご確認ください。');

        return $next($request);
    }
}
