<?php

namespace Tests\Unit;

use App\Services\Import\ImportFailureMessage;
use Illuminate\Validation\ValidationException;
use RuntimeException;
use Tests\TestCase;

class ImportFailureMessageTest extends TestCase
{
    public function test_it_returns_actionable_messages_for_known_import_format_errors(): void
    {
        $exception = ValidationException::withMessages([
            'file' => 'XLSXに対応できない日付値があります。異常な値は jane@example.test です。',
        ]);

        $this->assertSame(
            'XLSXの日付セルを確認し、対応する日付形式に修正して再度プレビューしてください。',
            ImportFailureMessage::safeMessage($exception),
        );
    }

    public function test_it_keeps_import_row_limit_messages_actionable(): void
    {
        $message = 'CSVの取込上限（20,000行）を超えています。ファイルを分割して再度お試しください。';
        $exception = ValidationException::withMessages(['file' => $message]);

        $this->assertSame($message, ImportFailureMessage::safeMessage($exception));
    }

    public function test_it_does_not_expose_unknown_validation_or_runtime_messages(): void
    {
        $validationException = ValidationException::withMessages(['file' => '取込値 jane@example.test を処理できません。']);

        $this->assertNull(ImportFailureMessage::safeMessage($validationException));
        $this->assertNull(ImportFailureMessage::safeMessage(new RuntimeException('API token secret-value')));
    }
}
