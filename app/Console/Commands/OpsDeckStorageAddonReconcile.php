<?php

namespace App\Console\Commands;

use App\Services\StorageAddonReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('opsdeck:storage-addon:reconcile')]
#[Description('Compare Stripe storage add-on quantities with OpsDeck billing entitlements')]
class OpsDeckStorageAddonReconcile extends Command
{
    public function handle(StorageAddonReconciliationService $reconciliationService): int
    {
        if (! config('opsdeck.release_features.storage_addon')) {
            $this->line('Storage add-on reconciliation: disabled');

            return self::SUCCESS;
        }

        $result = $reconciliationService->reconcile();
        $this->line('Storage add-on reconciliation: '.$result['status']);
        $this->line('Organizations checked: '.$result['organizations_checked']);
        $this->line('Mismatches: '.$result['mismatch_count']);
        $this->line('Unavailable: '.$result['unavailable_count']);
        foreach ($result['mismatch_organization_ids'] as $organizationId) {
            $this->warn('Review storage add-on billing for organization ID '.$organizationId.'.');
        }
        foreach ($result['unavailable_organization_ids'] as $organizationId) {
            $this->warn('Could not verify storage add-on billing for organization ID '.$organizationId.'.');
        }

        return in_array($result['status'], ['verified', 'not_configured'], true) ? self::SUCCESS : self::FAILURE;
    }
}
