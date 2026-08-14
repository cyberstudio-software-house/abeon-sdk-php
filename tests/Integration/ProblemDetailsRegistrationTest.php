<?php

declare(strict_types=1);

namespace Abeon\SDK\Tests\Integration;

use Abeon\SDK\AbeonServiceProvider;
use Abeon\SDK\Exceptions\AuthException;
use Abeon\SDK\Http\ProblemDetailsRenderer;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Http\Exceptions\HttpResponseException;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\TestCase;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

/**
 * `ProblemDetailsRenderer::register()` through the real exception handler.
 *
 * Every other test in this file's neighbourhood calls the renderer's methods directly,
 * which proves they format correctly and nothing about whether Laravel ever reaches
 * them. The two defects this suite exists for both lived in that gap: which callback
 * matches first, and what the chain does with an exception no callback claims.
 *
 * Both were also invisible locally — the catch-all returns null in debug mode, so the
 * chain carries on and development behaves correctly while production does not. These
 * tests therefore run with `app.debug` **false**.
 */
final class ProblemDetailsRegistrationTest extends TestCase
{
    protected function getPackageProviders($app): array
    {
        return [AbeonServiceProvider::class];
    }

    protected function defineEnvironment($app): void
    {
        $app['config']->set('app.debug', false);
        $app['config']->set('abeon.auth.issuer', 'abeon-auth');

        // What a service's `bootstrap/app.php` does. The *contract* has to be resolved,
        // not the concrete class — `make(Handler::class)` builds a second instance that
        // nothing ever renders through, and the registration silently does nothing.
        ProblemDetailsRenderer::register(new Exceptions($app->make(ExceptionHandler::class)));
    }

    protected function defineRoutes($router): void
    {
        $router->get('/boom/abeon', fn () => throw AuthException::forbidden('Nope'));
        $router->get('/boom/not-found', fn () => throw new NotFoundHttpException('Notification not found'));
        $router->get('/boom/model', fn () => throw new NotFoundHttpException(
            'No query results for model [App\\Models\\Organisation] 42',
            new ModelNotFoundException(),
        ));
        $router->get('/boom/prepared', fn () => throw new HttpResponseException(
            response()->json(['deliberate' => true], 418),
        ));
        $router->get('/boom/unexpected', fn () => throw new \RuntimeException('SQLSTATE[HY000] near "users"'));
    }

    public function test_an_abeon_exception_renders_as_its_own_problem(): void
    {
        $this->getJson('/boom/abeon')
            ->assertStatus(403)
            ->assertHeader('Content-Type', 'application/problem+json')
            ->assertJsonPath('type', 'https://api.abeon.pl/errors/forbidden');
    }

    public function test_a_framework_http_exception_renders_as_a_problem(): void
    {
        $this->getJson('/boom/not-found')
            ->assertStatus(404)
            ->assertJsonPath('type', 'https://api.abeon.pl/errors/not-found')
            // Written at a call site, meant for the caller, so it survives.
            ->assertJsonPath('detail', 'Notification not found');
    }

    public function test_a_model_lookup_does_not_leak_its_class_or_id(): void
    {
        // `prepareException()` maps `ModelNotFoundException` into an HTTP 404 carrying
        // `No query results for model [App\Models\Organisation] 42` — an internal class
        // name plus confirmation that somebody else's record does or does not exist.
        // `render()` was hiding exception messages two methods away while this path
        // published them.
        $response = $this->getJson('/boom/model')->assertStatus(404);

        $this->assertArrayNotHasKey('detail', (array) $response->json());
    }

    public function test_a_prepared_response_survives_the_catch_all(): void
    {
        // `abort($response)`, `throwResponse()` and Precognition all raise
        // `HttpResponseException`, which is a plain `RuntimeException` — no callback
        // above the catch-all matches it, and the catch-all runs *before* the branch in
        // `Handler::render()` that would return the response somebody deliberately
        // built. It used to become a blank 500, and only in production.
        $this->getJson('/boom/prepared')
            ->assertStatus(418)
            ->assertJsonPath('deliberate', true);
    }

    public function test_an_unexpected_throwable_becomes_a_problem_without_its_message(): void
    {
        $response = $this->getJson('/boom/unexpected')->assertStatus(500);

        $this->assertSame('about:blank', $response->json('type'));
        $this->assertArrayNotHasKey('detail', (array) $response->json());
    }
}
