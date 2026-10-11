<?php

namespace Tests\Feature;

use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class DemoControllerTest extends TestCase
{
    public function test_guest_can_view_a_synthetic_demo_without_database_or_external_side_effects(): void
    {
        $databaseQueries = [];
        DB::listen(static function (QueryExecuted $query) use (&$databaseQueries): void {
            $databaseQueries[] = $query->sql;
        });
        Mail::fake();
        Queue::fake();
        Http::preventStrayRequests();

        $this->get(route('demo.show'))
            ->assertOk()
            ->assertSee('読み取り専用デモ')
            ->assertSee('サンプル組織のIT運用ダッシュボード')
            ->assertSee('月次コスト')
            ->assertSee('重複利用の確認候補')
            ->assertSee('削減実績')
            ->assertSee('社員CSVの取込前プレビュー')
            ->assertSee('employee_number')
            ->assertSee('社員番号')
            ->assertSee('Import Preview結果の例（架空データ）')
            ->assertSee('新規登録候補')
            ->assertSee('更新候補')
            ->assertSee('重複確認が必要')
            ->assertSee('エラー')
            ->assertSee('EMP-SAMPLE-043')
            ->assertSee('duplicate@example.test')
            ->assertSee('実際のCSVは処理しておらず、登録・更新・メール送信は行いません。')
            ->assertSee('入力・保存はできず')
            ->assertSee(route('register'))
            ->assertDontSee('<form', false);

        $this->assertSame([], $databaseQueries);
        Mail::assertNothingOutgoing();
        Queue::assertNothingPushed();
        Http::assertNothingSent();
    }
}
