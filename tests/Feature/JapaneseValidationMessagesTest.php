<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Validator;
use Tests\TestCase;

class JapaneseValidationMessagesTest extends TestCase
{
    public function test_common_validation_messages_are_japanese(): void
    {
        $this->useJapaneseValidationMessages();

        $validator = Validator::make(
            ['name' => '', 'email' => 'not-an-email', 'title' => str_repeat('a', 101)],
            ['name' => ['required'], 'email' => ['email'], 'title' => ['max:100']],
        );

        $this->assertSame('氏名・名称は必須です。', $validator->errors()->first('name'));
        $this->assertSame('メールアドレスには有効なメールアドレスを入力してください。', $validator->errors()->first('email'));
        $this->assertSame('タイトルは100文字以内で入力してください。', $validator->errors()->first('title'));
    }

    public function test_untranslated_validation_messages_fall_back_to_english(): void
    {
        $this->useJapaneseValidationMessages();

        $validator = Validator::make(['name' => 'two words'], ['name' => ['uppercase']]);

        $this->assertStringContainsString('must be uppercase', $validator->errors()->first('name'));
        $this->assertStringNotContainsString('validation.uppercase', $validator->errors()->first('name'));
    }

    private function useJapaneseValidationMessages(): void
    {
        $this->app->setLocale('ja');
        $this->app->setFallbackLocale('en');
    }
}
