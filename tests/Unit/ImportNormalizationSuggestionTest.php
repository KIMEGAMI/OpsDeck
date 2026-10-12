<?php

namespace Tests\Unit;

use App\Services\Import\ImportNormalizationSuggestionService;
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
}
