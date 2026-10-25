<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use RuntimeException;
use Tests\TestCase;

class ErrorReferenceTest extends TestCase
{
    public function test_non_production_server_error_is_logged_without_exception_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/__test/non-production-error-reference', fn () => throw new RuntimeException('USER_EMAIL_SENTINEL@example.test'));
        Log::spy();

        $response = $this->get('/__test/non-production-error-reference');

        $response->assertInternalServerError()
            ->assertHeader('X-OpsDeck-Error-Reference')
            ->assertSee('一時的なエラーが発生しました')
            ->assertDontSee('USER_EMAIL_SENTINEL@example.test', false)
            ->assertDontSee('Stack trace', false)
            ->assertDontSee('#0 ', false)
            ->assertDontSee(RuntimeException::class, false);

        $this->assertStringNotContainsString('USER_EMAIL_SENTINEL@example.test', $response->getContent());

        $loggedContexts = [];
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$loggedContexts): bool {
                $loggedContexts[] = ['message' => $message, 'context' => $context];

                return true;
            });

        $this->assertNotEmpty($loggedContexts);
        $this->assertCount(1, $loggedContexts);
        foreach ($loggedContexts as $loggedContext) {
            $context = $loggedContext['context'];
            $this->assertSame('Server request failed.', $loggedContext['message']);
            $this->assertIsString($context['error_reference'] ?? null);
            $this->assertIsString($context['exception_class'] ?? null);
            $this->assertIsString($context['source_file'] ?? null);
            $this->assertIsInt($context['source_line'] ?? null);
            $this->assertStringNotContainsString('USER_EMAIL_SENTINEL@example.test', json_encode($context) ?: '');
        }

        $this->assertContains(RuntimeException::class, array_column(array_column($loggedContexts, 'context'), 'exception_class'));
    }

    public function test_non_production_json_error_returns_a_safe_reference_without_exception_details(): void
    {
        config(['app.debug' => false]);
        Route::get('/api/__test/non-production-error-reference', fn () => throw new RuntimeException('API_SECRET_SENTINEL'));
        Log::spy();

        $response = $this->getJson('/api/__test/non-production-error-reference');

        $response->assertInternalServerError()
            ->assertJsonPath('message', 'サーバーでエラーが発生しました。')
            ->assertJsonStructure(['error_reference'])
            ->assertHeader('X-OpsDeck-Error-Reference');

        $this->assertStringNotContainsString('API_SECRET_SENTINEL', $response->getContent());
        $this->assertStringNotContainsString('Stack trace', $response->getContent());
        $this->assertStringNotContainsString('#0 ', $response->getContent());
        $this->assertStringNotContainsString(RuntimeException::class, $response->getContent());
        $this->assertSame($response->json('error_reference'), $response->headers->get('X-OpsDeck-Error-Reference'));

        $loggedContexts = [];
        Log::shouldHaveReceived('error')
            ->once()
            ->withArgs(function (string $message, array $context) use (&$loggedContexts): bool {
                $loggedContexts[] = ['message' => $message, 'context' => $context];

                return true;
            });

        $this->assertCount(1, $loggedContexts);
        $context = $loggedContexts[0]['context'];
        $this->assertSame('Server request failed.', $loggedContexts[0]['message']);
        $this->assertSame($response->json('error_reference'), $context['error_reference'] ?? null);
        $this->assertSame(RuntimeException::class, $context['exception_class'] ?? null);
        $this->assertStringNotContainsString('API_SECRET_SENTINEL', json_encode($context) ?: '');
    }

    public function test_production_html_error_shows_a_safe_reference_without_exception_details(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false]);
        Route::get('/__test/error-reference', fn () => throw new RuntimeException('USER_EMAIL_SENTINEL@example.test'));
        Log::spy();

        $response = $this->get('/__test/error-reference');

        $response->assertInternalServerError()
            ->assertHeader('X-OpsDeck-Error-Reference')
            ->assertSee('一時的なエラーが発生しました')
            ->assertDontSee('USER_EMAIL_SENTINEL@example.test', false)
            ->assertDontSee('Stack trace', false)
            ->assertDontSee('#0 ', false)
            ->assertDontSee(RuntimeException::class, false);

        $this->assertStringNotContainsString('USER_EMAIL_SENTINEL@example.test', $response->getContent());
        $this->assertMatchesRegularExpression('/[0-9a-f-]{36}/', $response->headers->get('X-OpsDeck-Error-Reference'));
        $this->assertStringContainsString(
            str_replace('&', '&amp;', route('support.create', ['category' => 'other', 'error_reference' => $response->headers->get('X-OpsDeck-Error-Reference')])),
            $response->getContent(),
        );
        $this->assertSafeCorrelatedDiagnosticLog(
            $response->headers->get('X-OpsDeck-Error-Reference'),
            'USER_EMAIL_SENTINEL@example.test',
        );
    }

    public function test_production_json_error_contains_a_reference_but_no_exception_details(): void
    {
        $this->app['env'] = 'production';
        config(['app.debug' => false]);
        Route::get('/api/__test/error-reference', fn () => throw new RuntimeException('API_SECRET_SENTINEL'));
        Log::spy();

        $response = $this->getJson('/api/__test/error-reference');

        $response->assertInternalServerError()
            ->assertJsonPath('message', 'サーバーでエラーが発生しました。')
            ->assertJsonStructure(['error_reference'])
            ->assertHeader('X-OpsDeck-Error-Reference');

        $this->assertStringNotContainsString('API_SECRET_SENTINEL', $response->getContent());
        $this->assertSame($response->json('error_reference'), $response->headers->get('X-OpsDeck-Error-Reference'));
        $this->assertSafeCorrelatedDiagnosticLog(
            $response->headers->get('X-OpsDeck-Error-Reference'),
            'API_SECRET_SENTINEL',
        );
    }

    private function assertSafeCorrelatedDiagnosticLog(string $errorReference, string $privateInput): void
    {
        $loggedContexts = [];
        Log::shouldHaveReceived('error')
            ->withArgs(function (string $message, array $context) use (&$loggedContexts): bool {
                $loggedContexts[] = ['message' => $message, 'context' => $context];

                return true;
            });

        $exceptionClasses = [];
        foreach ($loggedContexts as $loggedContext) {
            $context = $loggedContext['context'];
            $serializedContext = json_encode($context) ?: '';
            $this->assertSame('Server request failed.', $loggedContext['message']);
            $this->assertSame($errorReference, $context['error_reference'] ?? null);
            $this->assertIsString($context['exception_class'] ?? null);
            $this->assertIsString($context['source_file'] ?? null);
            $this->assertIsInt($context['source_line'] ?? null);
            $this->assertStringNotContainsString($privateInput, $serializedContext);
            $exceptionClasses[] = $context['exception_class'];
        }

        $this->assertContains(RuntimeException::class, $exceptionClasses);
    }
}
