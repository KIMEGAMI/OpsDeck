<?php

namespace Tests\Unit;

use App\Models\GoogleSheetConnection;
use App\Models\Organization;
use App\Services\Import\GoogleSheetsImportSource;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class GoogleSheetsImportSourceTest extends TestCase
{
    public function test_it_reads_spreadsheets_sheets_and_values(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $organization = Organization::query()->create(['name' => 'Sheets '.Str::random(8), 'slug' => 'sheets-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $connection = GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'token', 'refresh_token' => 'refresh']);
        Http::fake([
            'https://www.googleapis.com/drive/v3/files*' => Http::response(['files' => [['id' => 'sheet-id', 'name' => '台帳']]], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id/values/*' => Http::response(['values' => [['名前', 'メール'], ['Alice', 'alice@example.test']]], 200),
            'https://sheets.googleapis.com/v4/spreadsheets/sheet-id*' => Http::response(['sheets' => [['properties' => ['title' => '社員一覧', 'gridProperties' => ['rowCount' => 2]]]]], 200),
        ]);
        $source = new GoogleSheetsImportSource;

        $this->assertSame([['id' => 'sheet-id', 'name' => '台帳']], $source->spreadsheets($connection));
        $this->assertSame(['社員一覧'], $source->sheets($connection, 'sheet-id'));
        $this->assertSame(['名前', 'メール'], $source->parse($connection, 'sheet-id', '社員一覧')['headers']);
        $this->assertSame(['Alice', 'alice@example.test'], $source->parse($connection, 'sheet-id', '社員一覧')['rows'][0]['values']);
        $streamedRows = [];
        $streamed = $source->parseEach($connection, 'sheet-id', '社員一覧', static function (array $row) use (&$streamedRows): void {
            $streamedRows[] = $row;
        });
        $this->assertSame(['名前', 'メール'], $streamed['headers']);
        $this->assertSame(1, $streamed['total_rows']);
        $this->assertSame(['Alice', 'alice@example.test'], $streamedRows[0]['values']);
    }

    public function test_it_rejects_duplicate_google_sheet_headers(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $organization = Organization::query()->create(['name' => 'Duplicate headers '.Str::random(8), 'slug' => 'duplicate-headers-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $connection = GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'token', 'refresh_token' => 'refresh']);
        Http::fake(['https://sheets.googleapis.com/v4/spreadsheets/sheet-id/values/*' => Http::response([
            'values' => [['Employee name', ' Employee name '], ['Alice', 'Bob']],
        ], 200)]);

        try {
            (new GoogleSheetsImportSource)->parse($connection, 'sheet-id', 'Employees');
            $this->fail('Duplicate Google Sheets headers must be rejected after trimming.');
        } catch (ValidationException $exception) {
            $this->assertSame(['見出しに同じ名前、または複数の空欄があります。列名を一意にしてから再度アップロードしてください。'], $exception->errors()['file']);
        }
    }

    public function test_it_limits_mapping_samples_at_the_google_sheets_api(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $organization = Organization::query()->create(['name' => 'Sample '.Str::random(8), 'slug' => 'sample-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $connection = GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'token', 'refresh_token' => 'refresh']);
        Http::fake(['https://sheets.googleapis.com/v4/spreadsheets/sheet-id/values/*' => Http::response(['values' => [
            ['名前'], ['社員1'], ['社員2'], ['社員3'],
        ]], 200)]);

        $parsed = (new GoogleSheetsImportSource)->parse($connection, 'sheet-id', "社員's一覧", false, 2);

        $this->assertSame(['名前'], $parsed['headers']);
        $this->assertSame(['社員1', '社員2'], array_map(static fn (array $row): string => $row['values'][0], $parsed['rows']));
        Http::assertSent(fn ($request): bool => str_contains($request->url(), '%27%E7%A4%BE%E5%93%A1%27%27s%E4%B8%80%E8%A6%A7%27%211%3A3'));
    }

    public function test_parse_each_fetches_bounded_ranges_and_preserves_physical_row_numbers(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.import.google_sheets_read_chunk_rows' => 2]);
        $organization = Organization::query()->create(['name' => 'Chunked '.Str::random(8), 'slug' => 'chunked-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $connection = GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'token', 'refresh_token' => 'refresh']);
        $requestedRanges = [];
        Http::fake(function (Request $request) use (&$requestedRanges) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/spreadsheets/sheet-id')) {
                return Http::response(['sheets' => [['properties' => ['title' => '社員一覧', 'gridProperties' => ['rowCount' => 5]]]]], 200);
            }

            $range = urldecode(substr($path, strpos($path, '/values/') + strlen('/values/')));
            $requestedRanges[] = $range;

            return match ($range) {
                "'社員一覧'!1:2" => Http::response(['values' => [['名前'], ['Alice']]], 200),
                "'社員一覧'!3:4" => Http::response(['values' => [[''], ['Bob']]], 200),
                "'社員一覧'!5:5" => Http::response(['values' => [['Carol']]], 200),
                default => Http::response([], 404),
            };
        });
        $rows = [];

        $parsed = (new GoogleSheetsImportSource)->parseEach($connection, 'sheet-id', '社員一覧', static function (array $row) use (&$rows): void {
            $rows[] = $row;
        });

        $this->assertSame(['名前'], $parsed['headers']);
        $this->assertSame(3, $parsed['total_rows']);
        $this->assertSame([2, 4, 5], array_column($rows, 'source_row_number'));
        $this->assertSame(["'社員一覧'!1:2", "'社員一覧'!3:4", "'社員一覧'!5:5"], $requestedRanges);
    }

    public function test_it_composes_multiple_header_rows_and_starts_data_at_the_selected_row(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        config(['opsdeck.import.google_sheets_read_chunk_rows' => 2]);
        $organization = Organization::query()->create(['name' => 'Multi header '.Str::random(8), 'slug' => 'multi-header-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $connection = GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'token', 'refresh_token' => 'refresh']);
        $requestedRanges = [];
        Http::fake(function (Request $request) use (&$requestedRanges) {
            $path = (string) parse_url($request->url(), PHP_URL_PATH);
            if (str_ends_with($path, '/spreadsheets/sheet-id')) {
                return Http::response(['sheets' => [['properties' => ['title' => '社員一覧', 'gridProperties' => ['rowCount' => 5]]]]], 200);
            }

            $range = urldecode(substr($path, strpos($path, '/values/') + strlen('/values/')));
            $requestedRanges[] = $range;

            return match ($range) {
                "'社員一覧'!1:2" => Http::response(['values' => [['注記'], ['社員', '部署']]], 200),
                "'社員一覧'!3:4" => Http::response(['values' => [['名前', '名称'], ['Alice', '営業']]], 200),
                "'社員一覧'!5:5" => Http::response(['values' => [['Bob', '経理']]], 200),
                default => Http::response([], 404),
            };
        });
        $rows = [];

        $parsed = (new GoogleSheetsImportSource)->parseEach(
            $connection,
            'sheet-id',
            '社員一覧',
            static function (array $row, array $headers) use (&$rows): void {
                $rows[] = ['row' => $row, 'headers' => $headers];
            },
            headerRowNumber: 2,
            headerEndRowNumber: 3,
            dataStartRowNumber: 4,
        );

        $this->assertSame(['社員 / 名前', '部署 / 名称'], $parsed['headers']);
        $this->assertSame([4, 5], array_column(array_column($rows, 'row'), 'source_row_number'));
        $this->assertSame(['社員 / 名前', '部署 / 名称'], $rows[0]['headers']);
        $this->assertSame(["'社員一覧'!1:2", "'社員一覧'!3:4", "'社員一覧'!5:5"], $requestedRanges);
    }

    public function test_it_rejects_revoked_connections(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $organization = Organization::query()->create(['name' => 'Revoked '.Str::random(8), 'slug' => 'revoked-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $connection = GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'token', 'refresh_token' => 'refresh', 'revoked_at' => now()]);

        $this->expectException(HttpException::class);
        (new GoogleSheetsImportSource)->spreadsheets($connection);
    }

    public function test_it_refreshes_an_expired_access_token(): void
    {
        $this->artisan('migrate', ['--force' => true])->assertExitCode(0);
        $organization = Organization::query()->create(['name' => 'Refresh '.Str::random(8), 'slug' => 'refresh-'.Str::lower(Str::random(8)), 'timezone' => config('opsdeck.default_timezone'), 'plan_code' => config('opsdeck.default_plan_code'), 'billing_status' => config('opsdeck.default_billing_status')]);
        $connection = GoogleSheetConnection::query()->create(['organization_id' => $organization->id, 'access_token' => 'expired', 'refresh_token' => 'refresh', 'token_expires_at' => now()->subMinute()]);
        config(['services.google.client_id' => 'client-id', 'services.google.client_secret' => 'client-secret']);
        Http::fake([
            'https://oauth2.googleapis.com/token' => Http::response(['access_token' => 'renewed', 'expires_in' => 3600], 200),
            'https://www.googleapis.com/drive/v3/files*' => Http::response(['files' => []], 200),
        ]);

        (new GoogleSheetsImportSource)->spreadsheets($connection);

        $this->assertSame('renewed', $connection->refresh()->access_token);
        Http::assertSent(fn ($request): bool => str_starts_with($request->url(), 'https://www.googleapis.com/drive/v3/files') && $request->hasHeader('Authorization', 'Bearer renewed'));
    }
}
