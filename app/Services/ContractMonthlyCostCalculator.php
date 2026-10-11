<?php

namespace App\Services;

use App\Models\Contract;
use InvalidArgumentException;

class ContractMonthlyCostCalculator
{
    public const int CENTS_PER_CURRENCY_UNIT = 100;

    public const int MONTHS_PER_YEAR = 12;

    /** @var list<string> */
    public const array RECURRING_BILLING_CYCLES = ['monthly', 'annual'];

    /**
     * @param  iterable<Contract>  $contracts
     * @return array<string, int>
     */
    public function monthlyTotalsInCents(iterable $contracts): array
    {
        $totals = [];

        foreach ($contracts as $contract) {
            $monthlyAmountInCents = $this->monthlyAmountInCents($contract);
            if ($monthlyAmountInCents === null) {
                continue;
            }

            $totals[$contract->currency] = ($totals[$contract->currency] ?? 0) + $monthlyAmountInCents;
        }

        return $totals;
    }

    public function monthlyTotalInCents(iterable $contracts, ?string $currency = null): int
    {
        $totalInCents = 0;

        foreach ($contracts as $contract) {
            if ($currency !== null && $contract->currency !== $currency) {
                continue;
            }

            $totalInCents += $this->monthlyAmountInCents($contract) ?? 0;
        }

        return $totalInCents;
    }

    public function monthlyAmountInCents(Contract $contract): ?int
    {
        if (! in_array($contract->billing_cycle, self::RECURRING_BILLING_CYCLES, true)) {
            return null;
        }

        $amountInCents = $this->amountInCents((string) $contract->amount);

        return $contract->billing_cycle === 'annual'
            ? intdiv($amountInCents, self::MONTHS_PER_YEAR)
            : $amountInCents;
    }

    public function annualAmountInCents(Contract $contract): ?int
    {
        if (! in_array($contract->billing_cycle, self::RECURRING_BILLING_CYCLES, true)) {
            return null;
        }

        $amountInCents = $this->amountInCents((string) $contract->amount);

        return $contract->billing_cycle === 'monthly'
            ? $amountInCents * self::MONTHS_PER_YEAR
            : $amountInCents;
    }

    public function currencyUnitsFromCents(int $amountInCents): float
    {
        return $amountInCents / self::CENTS_PER_CURRENCY_UNIT;
    }

    private function amountInCents(string $amount): int
    {
        if (preg_match('/\A(-?)(\d+)(?:\.(\d{1,2}))?\z/', $amount, $matches) !== 1) {
            throw new InvalidArgumentException('Contract amount must be a decimal with up to two fractional digits.');
        }

        $sign = $matches[1] === '-' ? -1 : 1;
        $wholeUnits = (int) $matches[2];
        $fractionalUnits = (int) str_pad($matches[3] ?? '', 2, '0');

        return $sign * (($wholeUnits * self::CENTS_PER_CURRENCY_UNIT) + $fractionalUnits);
    }
}
