<?php

namespace Tests\Unit;

use App\Services\CsvFormulaSanitizer;
use PHPUnit\Framework\TestCase;

class CsvFormulaSanitizerTest extends TestCase
{
    public function test_it_neutralizes_formula_like_values(): void
    {
        $sanitizer = new CsvFormulaSanitizer;

        $this->assertSame("\t=SUM(1,1)", $sanitizer->sanitize('=SUM(1,1)'));
        $this->assertSame("\t　+SUM(1,1)", $sanitizer->sanitize('　+SUM(1,1)'));
        $this->assertSame('-123.45', $sanitizer->sanitize('-123.45'));
        $this->assertSame('normal value', $sanitizer->sanitize('normal value'));
    }

    public function test_it_treats_invalid_utf8_as_text_instead_of_skipping_formula_checks(): void
    {
        $sanitizer = new CsvFormulaSanitizer;

        $this->assertSame("\t=SUM(1,1)\xFF", $sanitizer->sanitize("=SUM(1,1)\xFF"));
        $this->assertSame("\tnormal value\xFF", $sanitizer->sanitize("normal value\xFF"));
    }
}
