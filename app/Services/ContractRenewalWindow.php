<?php

namespace App\Services;

use App\Models\Contract;
use Carbon\CarbonInterface;

class ContractRenewalWindow
{
    public const ALERT_WINDOW_DAYS = 30;

    public const SAVINGS_WINDOW_DAYS = 90;

    /**
     * Return the earliest renewal or contract-end date inside the requested window.
     */
    public function dateWithin(Contract $contract, CarbonInterface $start, CarbonInterface $end): ?CarbonInterface
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        return $this->earliestDate($contract, static fn (CarbonInterface $date): bool => $date->toDateString() >= $startDate && $date->toDateString() <= $endDate);
    }

    public function dateOnOrBefore(Contract $contract, CarbonInterface $end): ?CarbonInterface
    {
        $endDate = $end->toDateString();

        return $this->earliestDate($contract, static fn (CarbonInterface $date): bool => $date->toDateString() <= $endDate);
    }

    public function reminderDateWithin(Contract $contract, CarbonInterface $start, CarbonInterface $end): ?CarbonInterface
    {
        $startDate = $start->toDateString();
        $endDate = $end->toDateString();

        return $this->earliestReminderDate($contract, static fn (CarbonInterface $date): bool => $date->toDateString() >= $startDate && $date->toDateString() <= $endDate);
    }

    public function reminderDateOnOrBefore(Contract $contract, CarbonInterface $end): ?CarbonInterface
    {
        $endDate = $end->toDateString();

        return $this->earliestReminderDate($contract, static fn (CarbonInterface $date): bool => $date->toDateString() <= $endDate);
    }

    public function reminderDateLabel(Contract $contract, CarbonInterface $date): string
    {
        if ($contract->cancellation_deadline?->toDateString() === $date->toDateString()) {
            return '解約申込期限';
        }

        if ($contract->renewal_date?->toDateString() === $date->toDateString()) {
            return '次回更新日';
        }

        return '契約終了日';
    }

    private function earliestDate(Contract $contract, callable $matchesWindow): ?CarbonInterface
    {
        $dates = array_values(array_filter([$contract->renewal_date, $contract->ends_at], static fn (?CarbonInterface $date): bool => $date !== null && $matchesWindow($date)));
        usort($dates, static fn (CarbonInterface $left, CarbonInterface $right): int => strcmp($left->toDateString(), $right->toDateString()));

        return $dates[0] ?? null;
    }

    private function earliestReminderDate(Contract $contract, callable $matchesWindow): ?CarbonInterface
    {
        $dates = array_values(array_filter(
            [$contract->cancellation_deadline, $contract->renewal_date, $contract->ends_at],
            static fn (?CarbonInterface $date): bool => $date !== null && $matchesWindow($date),
        ));
        usort($dates, static fn (CarbonInterface $left, CarbonInterface $right): int => strcmp($left->toDateString(), $right->toDateString()));

        return $dates[0] ?? null;
    }
}
