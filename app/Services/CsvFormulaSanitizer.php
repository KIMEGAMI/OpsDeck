<?php

namespace App\Services;

class CsvFormulaSanitizer
{
    public function sanitize(string $value): string
    {
        if ($value === '' || is_numeric($value)) {
            return $value;
        }

        $startsWithFormulaTrigger = preg_match('/^[\t\r\n]|^[\p{Z}\s\p{Cf}]*[=+\-@＝＋－＠]/u', $value);

        if ($startsWithFormulaTrigger === false) {
            return "\t{$value}";
        }

        return $startsWithFormulaTrigger === 1 ? "\t{$value}" : $value;
    }
}
