<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Query\Builder as QueryBuilder;

class AnnouncementRecipientQuery
{
    /**
     * @param  list<int>  $organizationIds
     * @return Builder<User>
     */
    public function eligibleFor(array $organizationIds = []): Builder
    {
        return User::query()->canReceiveNonTransactionalEmail()->whereHas('organizationMemberships', function (Builder $membershipQuery) use ($organizationIds): void {
            $membershipQuery->where('status', 'active')
                ->when($organizationIds !== [], fn (Builder $query): Builder => $query->whereIn('organization_id', $organizationIds))
                ->whereNotExists(function (QueryBuilder $preferenceQuery): void {
                    $preferenceQuery->selectRaw('1')
                        ->from('user_notification_preferences')
                        ->whereColumn('user_notification_preferences.user_id', 'organization_memberships.user_id')
                        ->whereColumn('user_notification_preferences.organization_id', 'organization_memberships.organization_id')
                        ->where('announcement_enabled', false);
                });
        });
    }
}
