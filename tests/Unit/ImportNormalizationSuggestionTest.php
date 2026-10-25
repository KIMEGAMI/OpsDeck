<?php

namespace Tests\Unit;

use App\Services\Import\ImportNormalizationSuggestionService;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class ImportNormalizationSuggestionTest extends TestCase
{
    public function test_it_returns_non_destructive_normalization_candidates(): void
    {
        $suggestions = (new ImportNormalizationSuggestionService)->suggest('contracts', ['金額', '自動更新', '開始日', 'メール'], [[
            '金額' => '¥9,800円', '自動更新' => 'はい', '開始日' => '2026/09/09', 'メール' => 'person@example.test',
        ]], ['金額' => 'amount', '自動更新' => 'auto_renew', '開始日' => 'started_at', 'メール' => 'vendor_email']);

        $this->assertCount(3, $suggestions);
        $this->assertSame('9800', collect($suggestions)->firstWhere('field', 'amount')['normalized']);
        $this->assertSame('true', collect($suggestions)->firstWhere('field', 'auto_renew')['normalized']);
        $this->assertSame('2026-09-09', collect($suggestions)->firstWhere('field', 'started_at')['normalized']);
    }

    public function test_it_masks_email_values_in_candidates(): void
    {
        $suggestions = (new ImportNormalizationSuggestionService)->suggest('employees', ['メール'], [['メール' => 'person@example.test']], ['メール' => 'email']);

        $this->assertSame([], $suggestions);
    }

    public function test_it_normalizes_numeric_dates_only_when_the_user_selects_an_order(): void
    {
        $normalizer = new ImportNormalizationSuggestionService;

        $this->assertSame(
            ['started_at' => '03/04/2026'],
            $normalizer->apply('contracts', ['started_at' => '03/04/2026'], ['started_at' => 'normalize_iso_date']),
        );
        $this->assertSame(
            ['started_at' => '2026-03-04'],
            $normalizer->apply('contracts', ['started_at' => '03/04/2026'], ['started_at' => 'normalize_month_first_date']),
        );
        $this->assertSame(
            ['started_at' => '2026-04-03'],
            $normalizer->apply('contracts', ['started_at' => '03/04/2026'], ['started_at' => 'normalize_day_first_date']),
        );
        $this->assertSame(
            ['started_at' => '31/02/2026'],
            $normalizer->apply('contracts', ['started_at' => '31/02/2026'], ['started_at' => 'normalize_day_first_date']),
        );
    }

    public function test_explicit_date_rules_are_limited_to_configured_date_fields(): void
    {
        $normalizer = new ImportNormalizationSuggestionService;

        $this->assertSame(
            ['started_at' => 'normalize_day_first_date'],
            $normalizer->validateRules('contracts', ['開始日' => 'started_at'], ['started_at' => 'normalize_day_first_date']),
        );

        $this->expectException(ValidationException::class);
        $normalizer->validateRules('contracts', ['金額' => 'amount'], ['amount' => 'normalize_day_first_date']);
    }

    public function test_it_applies_only_exact_value_mappings_to_allowed_mapped_fields(): void
    {
        $normalizer = new ImportNormalizationSuggestionService;
        $mappings = $normalizer->validateValueMappings('assets', ['種類' => 'asset_type'], [
            'asset_type' => [['source' => 'ノートPC', 'target' => 'pc']],
        ]);

        $this->assertSame(['asset_type' => [['source' => 'ノートPC', 'target' => 'pc']]], $mappings);
        $this->assertSame(['asset_type' => 'pc'], $normalizer->apply('assets', ['asset_type' => 'ノートPC'], [], $mappings));
        $this->assertSame(['asset_type' => 'その他'], $normalizer->apply('assets', ['asset_type' => 'その他'], [], $mappings));
    }

    public function test_it_rejects_unmapped_fields_and_duplicate_value_mapping_sources(): void
    {
        $normalizer = new ImportNormalizationSuggestionService;
        try {
            $normalizer->validateValueMappings('assets', ['種類' => 'model'], [
                'asset_type' => [['source' => 'ノートPC', 'target' => 'pc']],
            ]);
            $this->fail('An unmapped field must not accept value mappings.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }

        $this->expectException(ValidationException::class);
        $normalizer->validateValueMappings('assets', ['種類' => 'asset_type'], [
            'asset_type' => [
                ['source' => 'ノートPC', 'target' => 'pc'],
                ['source' => 'ノートPC', 'target' => 'other'],
            ],
        ]);
    }
}
